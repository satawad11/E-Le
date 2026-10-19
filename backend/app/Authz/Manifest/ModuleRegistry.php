<?php

namespace App\Authz\Manifest;

use App\Models\Authz\Module;
use Illuminate\Support\Facades\Schema;

/**
 * ทะเบียน manifest ของโมดูล (CORE-04 · PLAN §3.4 · R04-1)
 *
 * อ่าน `app/Modules/<Code>/manifest.php` แล้ว normalize ให้เป็นโครงเดียวกันทุกโมดูล:
 * permission เต็มชื่อ `{module}.{feature}.{action}` · template เต็มชื่อ `{module}.{code}` · `requires` เป็นชื่อเต็ม
 *
 * ไฟล์ manifest เป็นแหล่งเดียวของ permission/template/เมนู — ฐานข้อมูลเป็นสำเนาที่ `modules:sync` สร้าง
 * ส่วนสถานะเปิด/ปิดโมดูลอยู่ที่ `modules.enabled` (ผู้ดูแลเปลี่ยนได้โดยไม่แก้ไฟล์)
 *
 * หมายเหตุ: ทะเบียนโมดูลธุรกิจเดิม (`App\Services\Modules\ModuleRegistry` + `config/modules.php`) ยังใช้กับ
 * providers/route ของโมดูลเดิม และจะรวมเข้า manifest ใน R-08
 */
class ModuleRegistry
{
    public const SCOPES = ['own', 'unit', 'subtree', 'all', 'assigned'];

    public const RULES = ['not_own', 'maker_checker', 'hr_field_guard', 'grant_ceiling', 'two_person'];

    public const RISKS = ['low', 'medium', 'high'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $modules = null;

    public function __construct(private readonly ?string $path = null) {}

    /** @return array<string, array<string, mixed>> code => manifest (normalized) */
    public function all(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $modules = [];
        foreach (glob(($this->path ?? app_path('Modules')).'/*/manifest.php') ?: [] as $file) {
            $raw = require $file;
            if (! is_array($raw) || ! isset($raw['code'])) {
                throw new ManifestException("manifest ไม่ถูกต้อง: {$file}");
            }
            $modules[$raw['code']] = $this->normalize($raw, $file);
        }
        ksort($modules);

        (new ManifestValidator)->validate($modules);

        return $this->modules = $modules;
    }

    /** @return array<string, mixed>|null */
    public function get(string $code): ?array
    {
        return $this->all()[$code] ?? null;
    }

    /** @return array<string, array<string, mixed>> permission name => definition */
    public function permissions(): array
    {
        $permissions = [];
        foreach ($this->all() as $module) {
            foreach ($module['features'] as $feature) {
                foreach ($feature['actions'] as $action) {
                    $permissions[$action['permission']] = $action + ['module' => $module['code'], 'feature' => $feature['code']];
                }
            }
        }

        return $permissions;
    }

    /** @return array<string, array<string, mixed>> template code => definition */
    public function templates(): array
    {
        $templates = [];
        foreach ($this->all() as $module) {
            foreach ($module['templates'] as $template) {
                $templates[$template['code']] = $template;
            }
        }

        return $templates;
    }

    /** @return array<int, array<string, mixed>> */
    public function menuGroups(): array
    {
        $groups = [];
        foreach ($this->all() as $module) {
            foreach ($module['menu_groups'] as $group) {
                $groups[$group['code']] = $group + ['module' => $module['code']];
            }
        }
        uasort($groups, fn ($a, $b) => $a['order'] <=> $b['order']);

        return array_values($groups);
    }

    /** @return array<int, array<string, mixed>> */
    public function menus(): array
    {
        $menus = [];
        foreach ($this->all() as $module) {
            foreach ($module['menus'] as $menu) {
                $menus[] = $menu + ['module' => $module['code']];
            }
        }

        return $menus;
    }

    /**
     * โมดูลเปิดอยู่หรือไม่ — โมดูลที่ยังไม่เคย sync ถือว่าเปิด (ค่าใน manifest) · โมดูลที่ retired ถือว่าปิด
     */
    public function isEnabled(string $code): bool
    {
        if ($this->get($code) === null) {
            return false;
        }
        if (! Schema::hasTable('modules')) {
            return (bool) $this->get($code)['enabled'];
        }

        $row = Module::where('code', $code)->first(['enabled', 'retired_at']);

        return $row === null ? (bool) $this->get($code)['enabled'] : ($row->enabled && $row->retired_at === null);
    }

    public function hash(string $code): string
    {
        return hash('sha256', json_encode($this->get($code), JSON_UNESCAPED_UNICODE));
    }

    public function flush(): void
    {
        $this->modules = null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalize(array $raw, string $file): array
    {
        $code = $raw['code'];
        $qualify = fn (string $name) => substr_count($name, '.') === 1 ? "{$code}.{$name}" : $name;

        $features = [];
        $sort = 0;
        foreach ($raw['features'] ?? [] as $featureCode => $feature) {
            $actions = [];
            foreach ($feature['actions'] ?? [] as $actionCode => $action) {
                $actions[$actionCode] = [
                    'code' => $actionCode,
                    'permission' => "{$code}.{$featureCode}.{$actionCode}",
                    'name' => $action['name'] ?? $actionCode,
                    'scopes' => array_values($action['scopes'] ?? self::SCOPES),
                    'requires' => array_map($qualify, $action['requires'] ?? []),
                    'risk' => $action['risk'] ?? 'low',
                    'rules' => array_values($action['rules'] ?? []),
                ];
            }
            $features[$featureCode] = ['code' => $featureCode, 'name' => $feature['name'] ?? $featureCode, 'sort_order' => $sort++, 'actions' => $actions];
        }

        $templates = [];
        foreach ($raw['templates'] ?? [] as $templateCode => $template) {
            $templates["{$code}.{$templateCode}"] = [
                'code' => "{$code}.{$templateCode}",
                'module' => $code,
                'name' => $template['name'] ?? $templateCode,
                'kind' => $template['kind'] ?? 'functional',
                'description' => $template['description'] ?? null,
                'attributes_schema' => $template['attributes_schema'] ?? null,
                'permissions' => $template['permissions'] ?? [],
                'denies' => $template['denies'] ?? [],
            ];
        }

        $defaults = [];
        foreach ($raw['defaults'] ?? [] as $level => $codes) {
            $defaults[(int) ltrim($level, 'L')] = array_map(fn ($t) => str_contains($t, '.') ? $t : "{$code}.{$t}", $codes);
        }

        return [
            'code' => $code,
            'name' => $raw['name'] ?? $code,
            'version' => (string) ($raw['version'] ?? '0.0.0'),
            'enabled' => (bool) ($raw['enabled'] ?? true),
            'file' => $file,
            'features' => $features,
            'templates' => $templates,
            'defaults' => $defaults,
            'menu_groups' => array_map(fn ($g) => $g + ['order' => 0, 'icon' => null], $raw['menu_groups'] ?? []),
            'menus' => array_map(fn ($m) => $m + [
                'parent' => null, 'order' => 0, 'in_menu' => true, 'permission' => null, 'permission_pattern' => null,
                'scopes' => null, 'scoped_name' => null, 'badge' => null, 'phase' => null,
            ], $raw['menus'] ?? []),
            'workflows' => $raw['workflows'] ?? [],
            'notifications' => $raw['notifications'] ?? [],
            'audit_events' => $raw['audit_events'] ?? [],
        ];
    }
}
