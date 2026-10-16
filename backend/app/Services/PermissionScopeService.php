<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ให้/ถอนขอบเขตของสิทธิ์รายคน (`permission_scopes`) พร้อมบันทึก audit (ORG-ACCESS-01)
 *
 * ก่อนหน้านี้ไม่มีทางแก้ตารางนี้นอกจากเขียนลงฐานข้อมูลตรง ซึ่งไม่มี audit
 * (docs/ORG-POSITION-ACCESS-MAPPING.md ข้อ 5.7) ทุกการเปลี่ยนแปลงจึงต้องผ่านที่นี่
 *
 * กติกา:
 *   - ผู้สั่งต้องมีสิทธิ์ `admin.users.manage_permissions` และให้ขอบเขตแก่ตนเองไม่ได้
 *   - ต้องระบุเหตุผล (เช่น เลขที่คำสั่งแต่งตั้ง) ทุกครั้ง
 *   - ชื่อสิทธิ์เก่าเก็บเป็นชื่อ canonical และมีได้แถวเดียวต่อคนต่อสิทธิ์
 *     เพราะ `PermissionEvaluator::scopeFor()` อ่านแถวแรกที่ตรงชื่อใดชื่อหนึ่ง
 *   - CUSTOM ต้องระบุหน่วยงาน ขอบเขตอื่นห้ามระบุ
 */
class PermissionScopeService
{
    public const MANAGE_ABILITY = 'admin.users.manage_permissions';

    public const SCOPES = [
        OrgScopeResolver::SELF,
        OrgScopeResolver::UNIT,
        OrgScopeResolver::DESCENDANTS,
        OrgScopeResolver::ORGANIZATION,
        OrgScopeResolver::ASSIGNED,
        OrgScopeResolver::CUSTOM,
    ];

    public function __construct(
        private readonly PermissionEvaluator $evaluator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{row: object, warning: ?string}
     */
    public function grant(User $actor, User $user, string $permission, string $scope, ?int $orgUnitId, string $reason): array
    {
        $this->assertActor($actor);
        $reason = $this->assertReason($reason);

        if ($actor->is($user)) {
            throw new InvalidArgumentException('ให้ขอบเขตสิทธิ์แก่ตนเองไม่ได้ ต้องให้ผู้ดูแลคนอื่นเป็นผู้สั่ง');
        }

        $scope = strtoupper(trim($scope));
        if (! in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('ขอบเขตไม่ถูกต้อง ต้องเป็นหนึ่งใน '.implode(', ', self::SCOPES));
        }

        if ($scope === OrgScopeResolver::CUSTOM && $orgUnitId === null) {
            throw new InvalidArgumentException('ขอบเขต CUSTOM ต้องระบุหน่วยงาน');
        }

        if ($scope !== OrgScopeResolver::CUSTOM && $orgUnitId !== null) {
            throw new InvalidArgumentException('ระบุหน่วยงานได้เฉพาะขอบเขต CUSTOM');
        }

        if ($orgUnitId !== null && ! DB::table('org_units')->where('id', $orgUnitId)->where('is_active', true)->exists()) {
            throw new InvalidArgumentException('ไม่พบหน่วยงานที่ใช้งานอยู่');
        }

        $canonical = $this->canonicalPermission($permission);

        $row = DB::transaction(function () use ($actor, $user, $canonical, $scope, $orgUnitId, $reason) {
            $existing = $this->rowsFor($user, $canonical);

            DB::table('permission_scopes')->whereIn('id', $existing->pluck('id'))->delete();

            $id = DB::table('permission_scopes')->insertGetId([
                'model_type' => $user->getMorphClass(),
                'model_id' => $user->getKey(),
                'permission_name' => $canonical,
                'scope' => $scope,
                'org_unit_id' => $orgUnitId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('permission_scopes')->find($id);

            $this->audit->log(
                'access.scope.granted',
                'permission_scope',
                $id,
                $existing->isEmpty() ? null : ['rows' => $existing->map(fn (object $old) => $this->snapshot($old))->all()],
                $this->snapshot($row),
                $reason,
                $actor->getKey(),
            );

            return $row;
        });

        $warning = $this->evaluator->allows($user, $canonical)
            ? null
            : "ผู้ใช้ยังไม่มีสิทธิ์ {$canonical} ขอบเขตนี้จะยังไม่มีผลจนกว่าจะได้รับสิทธิ์";

        return ['row' => $row, 'warning' => $warning];
    }

    public function revoke(User $actor, User $user, string $permission, string $reason): int
    {
        $this->assertActor($actor);
        $reason = $this->assertReason($reason);

        $canonical = $this->canonicalPermission($permission);

        return DB::transaction(function () use ($actor, $user, $canonical, $reason) {
            $existing = $this->rowsFor($user, $canonical);

            if ($existing->isEmpty()) {
                throw new InvalidArgumentException("ผู้ใช้ไม่มีขอบเขตที่กำหนดไว้สำหรับสิทธิ์ {$canonical}");
            }

            DB::table('permission_scopes')->whereIn('id', $existing->pluck('id'))->delete();

            foreach ($existing as $old) {
                $this->audit->log(
                    'access.scope.revoked',
                    'permission_scope',
                    $old->id,
                    $this->snapshot($old),
                    null,
                    $reason,
                    $actor->getKey(),
                );
            }

            return $existing->count();
        });
    }

    /**
     * @return Collection<int,object>
     */
    public function list(?User $user = null): Collection
    {
        return DB::table('permission_scopes')
            ->leftJoin('users', function ($join) {
                $join->on('users.id', '=', 'permission_scopes.model_id')
                    ->where('permission_scopes.model_type', (new User)->getMorphClass());
            })
            ->leftJoin('org_units', 'org_units.id', '=', 'permission_scopes.org_unit_id')
            ->when($user, fn ($query) => $query
                ->where('permission_scopes.model_type', $user->getMorphClass())
                ->where('permission_scopes.model_id', $user->getKey()))
            ->orderBy('users.email')
            ->orderBy('permission_scopes.permission_name')
            ->get([
                'permission_scopes.id',
                'users.email',
                'users.name',
                'permission_scopes.permission_name',
                'permission_scopes.scope',
                'org_units.code as org_unit_code',
                'permission_scopes.updated_at',
            ]);
    }

    /**
     * ชื่อเก่าแปลงเป็นชื่อ canonical และต้องมี permission นั้นอยู่จริง
     */
    public function canonicalPermission(string $permission): string
    {
        $permission = trim($permission);

        $canonical = DB::table('permission_aliases')->where('legacy_name', $permission)->value('canonical_name') ?? $permission;

        $exists = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', $this->evaluator->equivalentNames($canonical))
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException("ไม่พบสิทธิ์ {$permission}");
        }

        return $canonical;
    }

    /**
     * @return Collection<int,object>
     */
    private function rowsFor(User $user, string $canonical): Collection
    {
        return DB::table('permission_scopes')
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->whereIn('permission_name', $this->evaluator->equivalentNames($canonical))
            ->lockForUpdate()
            ->get();
    }

    private function assertActor(User $actor): void
    {
        if (! $actor->is_active || ! $this->evaluator->allows($actor, self::MANAGE_ABILITY)) {
            throw new InvalidArgumentException('ผู้สั่งต้องเป็นผู้ใช้ที่ใช้งานอยู่และมีสิทธิ์จัดการสิทธิ์ผู้ใช้ ('.self::MANAGE_ABILITY.')');
        }
    }

    private function assertReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('ต้องระบุเหตุผล เช่น เลขที่คำสั่งแต่งตั้ง');
        }

        return $reason;
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(object $row): array
    {
        return [
            'model_type' => $row->model_type,
            'model_id' => (int) $row->model_id,
            'permission_name' => $row->permission_name,
            'scope' => $row->scope,
            'org_unit_id' => $row->org_unit_id === null ? null : (int) $row->org_unit_id,
        ];
    }
}
