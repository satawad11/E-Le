<?php

namespace App\Authz;

use App\Authz\Contracts\AuthzResource;
use App\Authz\Contracts\ScopedByAuthz;
use App\Authz\Manifest\ModuleRegistry;
use App\Models\Assignment;
use App\Models\Authz\Module;
use App\Models\Authz\Permission;
use App\Models\Authz\PermissionOverride;
use App\Models\Authz\PositionTemplate;
use App\Models\Authz\RoleGrant;
use App\Models\Authz\TemplatePermission;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\Personnel\FieldClassificationRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * จุดตัดสินสิทธิ์จุดเดียวของระบบ (CORE-04 · R04-3)
 *
 * สิทธิ์ที่มีผล = ⋃ (permission, scope) จากทุก assignment ที่มีผล ณ วันนั้น
 *              ∪ role grants ที่ยังไม่หมดอายุ ∪ override allow
 *              − deny (ชุดสิทธิ์ที่ประกาศ denies และ override deny — deny ชนะเสมอ)
 *              → resource ต้องอยู่ใน scope ของ "แหล่งเดียวกัน" → กฎกลาง → ผล
 *
 * - assignment ได้ชุดสิทธิ์จาก `position_templates`: ผูกกับตำแหน่งโดยตรง หรือกับระดับ
 *   (L1–L4 = management/acting ในหน่วยระดับนั้น · L5 = ทุกตำแหน่งหลัก) · scope unit/subtree ยึดหน่วยของ assignment นั้น
 * - acting มีผลเฉพาะช่วงคำสั่งและได้สิทธิ์ของตำแหน่งต้นทาง ยึดหน่วยต้นทาง
 * - ไม่มีบัญชีใดข้ามการตรวจ — ไม่มี Super Admin bypass
 * - cache ต่อผู้ใช้ต่อวัน ล้างเมื่อ AssignmentChanged / grant / override / template เปลี่ยน (version)
 */
class PermissionEngine
{
    private const SCOPE_RANK = ['own' => 0, 'assigned' => 1, 'unit' => 1, 'subtree' => 2, 'all' => 3];

    /** @var array<string, array<int, Grant>> */
    private array $memo = [];

    /** @var array<string, Permission|null> */
    private array $permissionCache = [];

    /** @var array<int, string> */
    private array $pathCache = [];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly FieldClassificationRegistry $fields,
    ) {}

    // ------------------------------------------------------------------ ตัดสิน

    /**
     * @param  array{fields?: array<int, string>, entity?: string}  $context  ใช้กับกฎ hr_field_guard
     */
    public function can(User $user, string $permission, ?AuthzResource $resource = null, array $context = []): Decision
    {
        $steps = [];

        if (! $user->isActive()) {
            return $this->deny(Decision::INACTIVE_ACCOUNT, 'บัญชีไม่อยู่ในสถานะใช้งาน', $steps);
        }
        $steps[] = ['step' => 'identity', 'result' => true, 'message' => 'บัญชีใช้งานได้'];

        $definition = $this->permission($permission);
        if ($definition === null) {
            return $this->deny(Decision::UNKNOWN_PERMISSION, "ไม่มีสิทธิ์ชื่อ {$permission} ใน manifest", $steps);
        }

        $module = explode('.', $permission)[0];
        if (! $this->registry->isEnabled($module)) {
            return $this->deny(Decision::MODULE_DISABLED, "โมดูล {$module} ปิดอยู่", $steps);
        }

        $grants = $this->grantsFor($user, $permission);
        $steps[] = ['step' => 'grants', 'result' => $grants !== [], 'message' => count($grants).' แหล่งที่ให้สิทธิ์นี้',
            'grants' => array_map(fn (Grant $g) => $g->toArray(), $grants)];

        if ($grants === []) {
            return $this->deny(Decision::NO_PERMISSION, "ไม่มีสิทธิ์ {$permission} จากตำแหน่ง ชุดสิทธิ์ หรือสิทธิ์รายบุคคลใด", $steps);
        }

        if ($resource === null) {
            $steps[] = ['step' => 'result', 'result' => true, 'message' => 'อนุญาต (ไม่ระบุรายการ)'];

            return new Decision(true, Decision::ALLOWED, 'อนุญาต', $grants[0], null, $steps);
        }

        $matching = null;
        foreach ($grants as $grant) {
            if ($this->grantCovers($user, $grant, $resource)) {
                $matching = $grant;
                break;
            }
        }
        $steps[] = ['step' => 'scope', 'result' => $matching !== null,
            'message' => $matching ? "อยู่ในขอบเขต {$matching->scope} ของ {$matching->label}" : 'รายการอยู่นอกขอบเขตของทุกแหล่ง'];

        if ($matching === null) {
            return $this->deny(Decision::OUT_OF_SCOPE, 'รายการอยู่นอกขอบเขตสิทธิ์', $steps);
        }

        foreach ($definition->rules ?? [] as $rule) {
            $violation = $this->ruleViolation($rule, $user, $resource, $context);
            $steps[] = ['step' => 'rule', 'rule' => $rule, 'result' => $violation === null, 'message' => $violation ?? "ผ่านกฎ {$rule}"];
            if ($violation !== null) {
                return new Decision(false, Decision::RULE_DENIED, $violation, $matching, $rule, $steps);
            }
        }

        $steps[] = ['step' => 'result', 'result' => true, 'message' => 'อนุญาต'];

        return new Decision(true, Decision::ALLOWED, 'อนุญาต', $matching, null, $steps);
    }

    public function allows(User $user, string $permission, ?AuthzResource $resource = null, array $context = []): bool
    {
        return $this->can($user, $permission, $resource, $context)->allowed;
    }

    /**
     * กรองรายการตาม scope ที่รวมจากทุกแหล่ง — ไม่มีสิทธิ์เลย = ไม่มีรายการ
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeQuery(User $user, string $permission, Builder $query): Builder
    {
        $model = $query->getModel();
        if (! $model instanceof ScopedByAuthz) {
            throw new LogicException(get_class($model).' ต้อง implement ScopedByAuthz จึงกรองตาม scope ได้');
        }

        $scope = $this->scopeFor($user, $permission);

        return $scope->isEmpty() ? $query->whereRaw('1 = 0') : $model->applyAuthzScope($query, $scope);
    }

    public function scopeFor(User $user, string $permission): AuthzScope
    {
        if (! $this->can($user, $permission)->allowed) {
            return new AuthzScope([]);
        }

        return AuthzScope::fromGrants($this->grantsFor($user, $permission), $user->personnel_id);
    }

    /**
     * สิทธิ์ที่มีผลทั้งหมด (permission, scope, แหล่ง) — ใช้กับ /api/me/permissions และหน้ารายละเอียดบัญชี
     *
     * @return Collection<int, Grant>
     */
    public function effective(User $user): Collection
    {
        return collect($this->allGrants($user))->filter(fn (Grant $g) => $this->registry->isEnabled(explode('.', $g->permission)[0]))->values();
    }

    /**
     * ขั้นตอนการตัดสินสำหรับ Permission Simulator (R-07)
     *
     * @return array{allowed: bool, reason: string, message: string, rule: ?string, source: ?array<string, mixed>, steps: array<int, array<string, mixed>>}
     */
    public function explain(User $user, string $permission, ?AuthzResource $resource = null, array $context = []): array
    {
        $decision = $this->can($user, $permission, $resource, $context);

        return [
            'allowed' => $decision->allowed,
            'reason' => $decision->reason,
            'message' => $decision->message,
            'rule' => $decision->rule,
            'source' => $decision->grant?->toArray()['source'],
            'steps' => $decision->steps,
        ];
    }

    /**
     * scope ทั้งหมดที่ผู้ใช้ถือสำหรับ permission นี้ (ว่าง = ไม่มีสิทธิ์ หรือโมดูลปิด)
     *
     * @return array<int, string>
     */
    public function scopesOf(User $user, string $permission): array
    {
        if (! $this->can($user, $permission)->allowed) {
            return [];
        }

        return array_values(array_unique(array_map(fn (Grant $g) => $g->scope, $this->grantsFor($user, $permission))));
    }

    /** scope ที่กว้างที่สุดของ permission (null = ไม่มีสิทธิ์) */
    public function widestScope(User $user, string $permission): ?string
    {
        $widest = null;
        foreach ($this->grantsFor($user, $permission) as $grant) {
            if ($widest === null || self::SCOPE_RANK[$grant->scope] > self::SCOPE_RANK[$widest]) {
                $widest = $grant->scope;
            }
        }

        return $widest;
    }

    /**
     * หน่วยที่ scope ยึด (สำหรับชื่อเมนูตามขอบเขต)
     *
     * @return array<int, int>
     */
    public function anchorUnitIds(User $user, string $permission): array
    {
        $ids = [];
        foreach ($this->grantsFor($user, $permission) as $grant) {
            if ($grant->scope !== 'own' && $grant->scope !== 'all') {
                $ids = array_merge($ids, array_keys($grant->anchors));
            }
        }

        return array_values(array_unique($ids));
    }

    // ------------------------------------------------------------------ กฎกลางตอนให้สิทธิ์

    /**
     * grant_ceiling — ผู้ให้ต้องมี permission นั้นใน scope ที่ ≥ ที่จะให้ และครอบคลุมหน่วยที่ให้
     */
    public function canGrant(User $granter, string $permission, string $scope, ?OrgUnit $target = null): bool
    {
        if (! isset(self::SCOPE_RANK[$scope])) {
            return false;
        }

        foreach ($this->grantsFor($granter, $permission) as $grant) {
            if (self::SCOPE_RANK[$grant->scope] < self::SCOPE_RANK[$scope]) {
                continue;
            }
            if ($grant->scope === 'all') {
                return true;
            }
            if ($target === null) {
                continue;
            }
            $targetPath = $this->pathOf($target->id);
            foreach ($grant->anchors as $anchorId => $anchorPath) {
                $covers = $grant->scope === 'subtree' ? str_starts_with((string) $targetPath, $anchorPath) : $anchorId === $target->id;
                if ($covers && ! $this->excluded($grant, (string) $targetPath)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * two_person — การเปลี่ยนที่เกี่ยวกับ permission ความเสี่ยงสูง หรือที่ประกาศกฎ two_person ต้องผ่านผู้ดูแลคนที่สอง
     *
     * @param  string|array<int, string>  $permissions
     */
    public function requiresSecondPerson(string|array $permissions): bool
    {
        foreach ((array) $permissions as $name) {
            $permission = $this->permission($name);
            if ($permission !== null && ($permission->risk === 'high' || in_array('two_person', $permission->rules ?? [], true))) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ cache

    public function flushUser(?User $user): void
    {
        if ($user === null) {
            return;
        }
        Cache::increment($this->userVersionKey($user->id));
        $this->memo = array_filter($this->memo, fn ($key) => ! str_starts_with($key, $user->id.'@'), ARRAY_FILTER_USE_KEY);
    }

    public function flushAll(): void
    {
        Cache::increment('authz:version');
        $this->memo = [];
        $this->permissionCache = [];
        $this->pathCache = [];
        $this->registry->flush();
    }

    // ------------------------------------------------------------------ ภายใน

    private function deny(string $reason, string $message, array $steps): Decision
    {
        $steps[] = ['step' => 'result', 'result' => false, 'message' => $message];

        return new Decision(false, $reason, $message, null, null, $steps);
    }

    private function permission(string $name): ?Permission
    {
        if (! array_key_exists($name, $this->permissionCache)) {
            $this->permissionCache[$name] = Permission::where('name', $name)->whereNull('retired_at')->first();
        }

        return $this->permissionCache[$name];
    }

    /** @return array<int, Grant> */
    private function grantsFor(User $user, string $permission): array
    {
        return array_values(array_filter($this->allGrants($user), fn (Grant $g) => $g->permission === $permission));
    }

    /** @return array<int, Grant> */
    private function allGrants(User $user): array
    {
        if (! $user->isActive()) {
            return [];
        }

        $date = today()->toDateString();
        $memoKey = $user->id.'@'.$date;
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $cacheKey = sprintf('authz:grants:%d:%s:%d:%d', $user->id, $date, (int) Cache::get('authz:version', 0), (int) Cache::get($this->userVersionKey($user->id), 0));
        $rows = Cache::remember($cacheKey, now()->addHours(12), fn () => array_map(fn (Grant $g) => $g->toArray(), $this->computeGrants($user, $date)));

        return $this->memo[$memoKey] = array_map(fn (array $row) => Grant::fromArray($row), $rows);
    }

    /** @return array<int, Grant> */
    private function computeGrants(User $user, string $date): array
    {
        $grants = [];
        $denies = [];
        $retired = Permission::whereNotNull('retired_at')->pluck('id')->all();
        $disabledModules = Module::where(fn ($q) => $q->where('enabled', false)->orWhereNotNull('retired_at'))->pluck('code')->all();

        // 1) assignment ที่มีผล ณ วันนั้น
        if ($user->personnel_id !== null) {
            $assignments = Assignment::with(['orgUnit.type', 'position'])
                ->where('personnel_id', $user->personnel_id)
                ->effectiveOn($date)
                ->get();

            foreach ($assignments as $assignment) {
                $level = match ($assignment->kind) {
                    Assignment::KIND_PRIMARY => 5,
                    Assignment::KIND_MANAGEMENT, Assignment::KIND_ACTING => $assignment->orgUnit->type->level,
                    default => null,
                };

                $templateIds = PositionTemplate::query()
                    ->where(fn ($q) => $q->where('position_id', $assignment->position_id)
                        ->when($level !== null, fn ($w) => $w->orWhere(fn ($x) => $x->whereNull('position_id')->where('org_unit_type_level', $level))))
                    ->pluck('template_id')->unique()->all();

                $label = ($assignment->kind === Assignment::KIND_ACTING ? 'รักษาการ' : '').$assignment->position->name.' · '.$assignment->orgUnit->name;
                $anchors = [$assignment->org_unit_id => $assignment->orgUnit->path];

                $this->collectTemplates($templateIds, Grant::SOURCE_ASSIGNMENT, $assignment->id, $label, $anchors, [], $grants, $denies, $retired);
            }
        }

        // 2) role grants (functional) ที่ยังไม่หมดอายุ
        $roleGrants = RoleGrant::with('template')
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->whereDate('effective_from', '<=', $date)
            ->whereDate('expires_at', '>=', $date)
            ->get();

        foreach ($roleGrants as $roleGrant) {
            if ($roleGrant->template === null || $roleGrant->template->retired_at !== null) {
                continue;
            }
            $unitIds = array_map('intval', $roleGrant->attributes['org_unit_ids'] ?? []);
            if ($unitIds === []) {
                $unitIds = array_filter([$this->primaryUnitId($user, $date)]);
            }
            $anchors = OrgUnit::whereIn('id', $unitIds)->pluck('path', 'id')->all();
            $label = $roleGrant->template->name.' (คำสั่ง '.$roleGrant->order_number.')';

            $this->collectTemplates([$roleGrant->template_id], Grant::SOURCE_ROLE_GRANT, $roleGrant->id, $label, $anchors, $roleGrant->attributes ?? [], $grants, $denies, $retired);
        }

        // 3) override รายบุคคล (allow ต้องมีวันหมดอายุ · deny ไม่มีก็ได้)
        $overrides = PermissionOverride::with(['permission', 'orgUnit'])
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', $date))
            ->get();

        foreach ($overrides as $override) {
            if ($override->permission === null || in_array($override->permission_id, $retired, true)) {
                continue;
            }
            if ($override->effect === PermissionOverride::EFFECT_DENY) {
                $denies[] = ['permission' => $override->permission->name, 'ceiling' => null, 'path' => $override->orgUnit?->path];

                continue;
            }
            if ($override->expires_at === null) {
                continue; // allow ที่ไม่มีวันหมดอายุไม่มีผล (PROJECT_RULES ข้อ 9)
            }
            $anchorUnit = $override->orgUnit ?? OrgUnit::find($this->primaryUnitId($user, $date));
            $grants[] = new Grant($override->permission->name, $override->scope ?? 'own', Grant::SOURCE_OVERRIDE, $override->id,
                'สิทธิ์รายบุคคล: '.$override->reason, $anchorUnit ? [$anchorUnit->id => $anchorUnit->path] : []);
        }

        // 4) deny ชนะเสมอ
        foreach ($denies as $deny) {
            foreach ($grants as $i => $grant) {
                if ($grant->permission !== $deny['permission']) {
                    continue;
                }
                if ($deny['path'] !== null) {
                    $grant->excludedPaths[] = $deny['path'];
                } elseif ($deny['ceiling'] === null || self::SCOPE_RANK[$grant->scope] > self::SCOPE_RANK[$deny['ceiling']]) {
                    unset($grants[$i]);
                }
            }
        }

        return array_values(array_filter($grants, fn (Grant $g) => ! in_array(explode('.', $g->permission)[0], $disabledModules, true)));
    }

    /**
     * @param  array<int, int>  $templateIds
     * @param  array<int, string>  $anchors
     * @param  array<string, mixed>  $attributes
     * @param  array<int, Grant>  $grants
     * @param  array<int, array{permission: string, ceiling: ?string, path: ?string}>  $denies
     * @param  array<int, int>  $retired
     */
    private function collectTemplates(array $templateIds, string $sourceType, int $sourceId, string $label, array $anchors, array $attributes, array &$grants, array &$denies, array $retired): void
    {
        if ($templateIds === []) {
            return;
        }

        $rows = TemplatePermission::with(['permission:id,name', 'template:id,code,retired_at'])->whereIn('template_id', $templateIds)->get();
        foreach ($rows as $row) {
            if ($row->template->retired_at !== null || in_array($row->permission_id, $retired, true)) {
                continue;
            }
            if ($row->effect === 'deny') {
                $denies[] = ['permission' => $row->permission->name, 'ceiling' => $row->scope, 'path' => null];

                continue;
            }
            $grants[] = new Grant($row->permission->name, $row->scope, $sourceType, $sourceId, $label, $anchors, $row->template->code, $attributes);
        }
    }

    private function primaryUnitId(User $user, string $date): ?int
    {
        if ($user->personnel_id === null) {
            return null;
        }

        return Assignment::where('personnel_id', $user->personnel_id)->where('kind', Assignment::KIND_PRIMARY)->effectiveOn($date)->value('org_unit_id');
    }

    /**
     * resource อยู่ในขอบเขตของแหล่งนี้หรือไม่
     *
     * หน่วยใดของ resource อยู่ใต้หน่วยที่ถูก deny (override จำกัดหน่วย) = ไม่ครอบคลุม ทุก scope รวม all และ own
     * (deny ชนะเสมอ) — กติกาเดียวกับ AuthzScope ที่ scopeQuery ใช้ ผลของ can() กับรายการจึงตรงกันทุกคู่
     */
    private function grantCovers(User $user, Grant $grant, AuthzResource $resource): bool
    {
        $paths = [];
        foreach ($resource->authzOrgUnitIds() as $unitId) {
            $path = $this->pathOf($unitId);
            if ($path === null) {
                continue;
            }
            if ($this->excluded($grant, $path)) {
                return false;
            }
            $paths[$unitId] = $path;
        }

        return match ($grant->scope) {
            'own' => $user->personnel_id !== null && $resource->authzOwnerPersonnelId() === $user->personnel_id,
            'all' => true,
            'subtree' => collect($paths)->contains(fn (string $path) => collect($grant->anchors)->contains(fn (string $anchor) => str_starts_with($path, $anchor))),
            default => array_intersect(array_keys($paths), array_map('intval', array_keys($grant->anchors))) !== [],
        };
    }

    private function excluded(Grant $grant, string $path): bool
    {
        foreach ($grant->excludedPaths as $excluded) {
            if (str_starts_with($path, $excluded)) {
                return true;
            }
        }

        return false;
    }

    private function pathOf(int $unitId): ?string
    {
        if (! array_key_exists($unitId, $this->pathCache)) {
            $this->pathCache[$unitId] = OrgUnit::whereKey($unitId)->value('path');
        }

        return $this->pathCache[$unitId];
    }

    /** @param  array{fields?: array<int, string>, entity?: string}  $context */
    private function ruleViolation(string $rule, User $user, AuthzResource $resource, array $context): ?string
    {
        $isOwn = $user->personnel_id !== null && $resource->authzOwnerPersonnelId() === $user->personnel_id;

        return match ($rule) {
            'not_own' => $isOwn ? 'ใช้อำนาจกับข้อมูลหรือรายการของตนเองไม่ได้ (not_own)' : null,
            'maker_checker' => $resource->authzMakerUserId() === $user->id ? 'ผู้สร้าง/ผู้ยื่นรายการต้องไม่ใช่ผู้ตรวจ/ผู้อนุมัติ (maker_checker)' : null,
            'hr_field_guard' => $isOwn && collect($context['fields'] ?? [])->contains(
                fn (string $field) => $this->fields->editableBy($context['entity'] ?? FieldClassificationRegistry::ENTITY_PERSONNEL, $field) === 'hr_only'
            ) ? 'แก้ฟิลด์ที่งานบุคคลเป็นผู้แก้ของตนเองไม่ได้ (hr_field_guard)' : null,
            // grant_ceiling และ two_person ตรวจตอนให้สิทธิ์/เปลี่ยนสิทธิ์ (canGrant, requiresSecondPerson)
            default => null,
        };
    }

    private function userVersionKey(int $userId): string
    {
        return "authz:user-version:{$userId}";
    }
}
