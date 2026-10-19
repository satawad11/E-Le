<?php

namespace App\Authz\Manifest;

use App\Authz\PermissionEngine;
use App\Models\Authz\Module;
use App\Models\Authz\ModuleFeature;
use App\Models\Authz\Permission;
use App\Models\Authz\PermissionAuditLog;
use App\Models\Authz\PermissionTemplate;
use App\Models\Authz\PositionTemplate;
use App\Models\Authz\TemplatePermission;
use Illuminate\Support\Facades\DB;

/**
 * `modules:sync` (CORE-04 · R04-1) — สร้าง/อัปเดต modules, features, permissions, templates จาก manifest
 *
 * - idempotent: รันซ้ำได้ ไม่สร้างซ้ำ
 * - ไม่ทับการปรับของผู้ดูแลใน `template_permissions` และ `position_templates` เว้นแต่ `--reset-defaults`
 *   (เพิ่มเฉพาะแถวที่ยังไม่มี)
 * - permission/feature/template/module ที่หายจาก manifest ถูก mark `retired_at` ไม่ลบ · กลับมาใน manifest = ยกเลิก retired
 * - ทุกการเปลี่ยนบันทึก permission_audit_logs และล้าง cache ของ PermissionEngine
 */
class ModuleSynchronizer
{
    /** @var array<string, int> */
    private array $stats = [];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly PermissionEngine $engine,
    ) {}

    /** @return array<string, int> */
    public function sync(bool $resetDefaults = false, ?int $actorId = null): array
    {
        $this->stats = ['modules' => 0, 'permissions_created' => 0, 'permissions_updated' => 0, 'permissions_retired' => 0,
            'templates_created' => 0, 'template_permissions_added' => 0, 'template_permissions_reset' => 0, 'position_templates_added' => 0];

        $this->registry->flush();
        $manifests = $this->registry->all();

        DB::transaction(function () use ($manifests, $resetDefaults, $actorId): void {
            $now = now();
            $seenPermissions = [];
            $seenTemplates = [];

            foreach ($manifests as $code => $manifest) {
                $module = Module::firstOrNew(['code' => $code]);
                $isNew = ! $module->exists;
                $module->fill([
                    'name' => $manifest['name'],
                    'version' => $manifest['version'],
                    'manifest_hash' => $this->registry->hash($code),
                    'synced_at' => $now,
                    'retired_at' => null,
                ]);
                if ($isNew) {
                    $module->enabled = $manifest['enabled'];
                }
                $module->save();
                $this->stats['modules']++;

                $seenFeatures = [];
                foreach ($manifest['features'] as $featureCode => $feature) {
                    $featureRow = ModuleFeature::updateOrCreate(
                        ['module_id' => $module->id, 'code' => $featureCode],
                        ['name' => $feature['name'], 'sort_order' => $feature['sort_order'], 'retired_at' => null],
                    );
                    $seenFeatures[] = $featureRow->id;

                    foreach ($feature['actions'] as $action) {
                        $this->syncPermission($featureRow, $action, $actorId);
                        $seenPermissions[] = $action['permission'];
                    }
                }
                ModuleFeature::where('module_id', $module->id)->whereNotIn('id', $seenFeatures)->whereNull('retired_at')->update(['retired_at' => $now]);
            }

            $retired = Permission::whereNotIn('name', $seenPermissions)->whereNull('retired_at')->get();
            foreach ($retired as $permission) {
                $permission->update(['retired_at' => $now]);
                $this->log('permission.retired', 'permission', $permission->id, ['name' => $permission->name], null, $actorId);
                $this->stats['permissions_retired']++;
            }
            Module::whereNotIn('code', array_keys($manifests))->whereNull('retired_at')->update(['retired_at' => $now]);

            foreach ($this->registry->templates() as $code => $template) {
                $this->syncTemplate($template, $resetDefaults, $actorId);
                $seenTemplates[] = $code;
            }
            PermissionTemplate::whereNotIn('code', $seenTemplates)->whereNull('retired_at')->update(['retired_at' => $now]);

            $this->syncDefaults($manifests, $resetDefaults, $actorId);
        });

        $this->engine->flushAll();

        return $this->stats;
    }

    /** @param  array<string, mixed>  $action */
    private function syncPermission(ModuleFeature $feature, array $action, ?int $actorId): void
    {
        $values = [
            'module_feature_id' => $feature->id,
            'action' => $action['code'],
            'label' => $action['name'],
            'allowed_scopes' => $action['scopes'],
            'requires' => $action['requires'],
            'risk' => $action['risk'],
            'rules' => $action['rules'],
            'retired_at' => null,
        ];

        $permission = Permission::where('name', $action['permission'])->first();
        if ($permission === null) {
            $permission = Permission::create(['name' => $action['permission']] + $values);
            $this->log('permission.created', 'permission', $permission->id, null, ['name' => $permission->name] + $values, $actorId);
            $this->stats['permissions_created']++;

            return;
        }

        $before = $permission->only(array_keys($values));
        $permission->fill($values);
        if ($permission->isDirty()) {
            $permission->save();
            $this->log('permission.updated', 'permission', $permission->id, $before, $values, $actorId);
            $this->stats['permissions_updated']++;
        }
    }

    /** @param  array<string, mixed>  $template */
    private function syncTemplate(array $template, bool $reset, ?int $actorId): void
    {
        $row = PermissionTemplate::where('code', $template['code'])->first();
        $moduleId = Module::where('code', $template['module'])->value('id');
        $attributes = [
            'module_id' => $moduleId,
            'name' => $template['name'],
            'kind' => $template['kind'],
            'description' => $template['description'],
            'attributes_schema' => $template['attributes_schema'],
            'retired_at' => null,
        ];

        if ($row === null) {
            $row = PermissionTemplate::create(['code' => $template['code']] + $attributes);
            $this->log('template.created', 'permission_template', $row->id, null, ['code' => $row->code], $actorId);
            $this->stats['templates_created']++;
        } else {
            $row->update($attributes);
        }

        $wanted = [];
        foreach ($template['permissions'] as $name => $scope) {
            $wanted[$name] = ['effect' => 'allow', 'scope' => $scope];
        }
        foreach ($template['denies'] as $pattern => $ceiling) {
            foreach ($this->expand($pattern) as $name) {
                $wanted[$name] = ['effect' => 'deny', 'scope' => $ceiling];
            }
        }

        $ids = Permission::whereIn('name', array_keys($wanted))->pluck('id', 'name');

        if ($reset) {
            $removed = TemplatePermission::where('template_id', $row->id)->delete();
            $this->stats['template_permissions_reset'] += $removed;
        }

        foreach ($wanted as $name => $entry) {
            $exists = TemplatePermission::where('template_id', $row->id)->where('permission_id', $ids[$name])->exists();
            if (! $exists) {
                TemplatePermission::create(['template_id' => $row->id, 'permission_id' => $ids[$name]] + $entry);
                $this->stats['template_permissions_added']++;
            }
        }

        if ($reset) {
            $this->log('template.reset_defaults', 'permission_template', $row->id, null, ['permissions' => $wanted], $actorId);
        }
    }

    /** @param  array<string, array<string, mixed>>  $manifests */
    private function syncDefaults(array $manifests, bool $reset, ?int $actorId): void
    {
        if ($reset) {
            PositionTemplate::whereNull('position_id')->whereNotNull('org_unit_type_level')->delete();
        }

        foreach ($manifests as $manifest) {
            foreach ($manifest['defaults'] as $level => $codes) {
                foreach ($codes as $code) {
                    $templateId = PermissionTemplate::where('code', $code)->value('id');
                    $row = PositionTemplate::firstOrCreate(['position_id' => null, 'org_unit_type_level' => $level, 'template_id' => $templateId]);
                    if ($row->wasRecentlyCreated) {
                        $this->log('position_template.created', 'position_template', $row->id, null, ['level' => $level, 'template' => $code], $actorId);
                        $this->stats['position_templates_added']++;
                    }
                }
            }
        }
    }

    /** @return array<int, string> */
    private function expand(string $pattern): array
    {
        if (! str_ends_with($pattern, '.*')) {
            return [$pattern];
        }
        $prefix = substr($pattern, 0, -1);

        return array_values(array_filter(array_keys($this->registry->permissions()), fn ($name) => str_starts_with($name, $prefix)));
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(string $action, string $type, ?int $id, ?array $before, ?array $after, ?int $actorId): void
    {
        PermissionAuditLog::create([
            'actor_user_id' => $actorId,
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $id,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
