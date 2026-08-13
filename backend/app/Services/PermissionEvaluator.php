<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * ตัวตัดสินสิทธิ์กลาง (LEGACY-04 · CORE-AUTHZ-004)
 *
 * ลำดับการตัดสินตาม docs/06-RBAC-ABAC.md:
 *
 *     explicit deny  >  scoped allow  >  role allow  >  default deny
 *
 * Spatie ให้ได้แค่ allow เท่านั้น ตัวนี้จึงห่อ Spatie ไว้อีกชั้นเพื่อเพิ่ม
 * explicit deny และ scope โดยไม่ต้องรื้อ RBAC เดิม
 *
 * เปิด/ปิดได้ด้วย config `authorization.use_new_engine`
 * (env `USE_NEW_PERMISSION_ENGINE`) เพื่อให้ย้อนกลับได้ทันทีถ้ามีปัญหา
 */
class PermissionEvaluator
{
    /** cache ของ alias ต่อ request เพื่อไม่ query ซ้ำ */
    private ?array $aliasMap = null;

    /**
     * ผู้ใช้มีสิทธิ์นี้หรือไม่
     *
     * @param  string  $ability  ชื่อ permission จะเป็นชื่อ canonical หรือชื่อเก่าก็ได้
     */
    public function allows(?User $user, string $ability): bool
    {
        if ($user === null) {
            return false;
        }

        // explicit deny ชนะทุกอย่าง รวมถึง Super Admin
        if ($this->isDenied($user, $ability)) {
            return false;
        }

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $this->hasAllow($user, $ability);
    }

    /**
     * ถูกปฏิเสธอย่างชัดแจ้งหรือไม่ — ตรวจทั้งชื่อที่ส่งมาและชื่อที่เทียบเท่า
     * เพื่อไม่ให้หลบ deny ได้ด้วยการเรียกด้วยชื่ออีกรุ่นหนึ่ง
     */
    public function isDenied(User $user, string $ability): bool
    {
        if (! $this->tableExists('permission_denies')) {
            return false;
        }

        return DB::table('permission_denies')
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->whereIn('permission_name', $this->equivalentNames($ability))
            ->exists();
    }

    /**
     * ขอบเขตของสิทธิ์นี้สำหรับผู้ใช้คนนี้
     *
     * คืน null เมื่อไม่ได้กำหนดขอบเขตไว้ ซึ่งหมายถึง "ไม่จำกัด" ตามพฤติกรรมเดิม
     *
     * @return array{scope: string, org_unit_id: ?int}|null
     */
    public function scopeFor(User $user, string $ability): ?array
    {
        if (! $this->tableExists('permission_scopes')) {
            return null;
        }

        $row = DB::table('permission_scopes')
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->whereIn('permission_name', $this->equivalentNames($ability))
            ->first();

        return $row === null ? null : ['scope' => $row->scope, 'org_unit_id' => $row->org_unit_id];
    }

    /**
     * ชื่อทั้งหมดที่ถือว่าเทียบเท่ากัน (ชื่อเก่า + ชื่อ canonical)
     *
     * ทำให้โค้ดที่เช็คชื่อ canonical ผ่านได้แม้ผู้ใช้ยังถือชื่อเก่าอยู่
     * และกันไม่ให้ deny ถูกหลบด้วยการเรียกอีกชื่อหนึ่ง
     *
     * @return array<int,string>
     */
    public function equivalentNames(string $ability): array
    {
        $map = $this->aliases();
        $names = [$ability];

        // ชื่อเก่า -> ชื่อ canonical
        if (isset($map['legacyToCanonical'][$ability])) {
            $names[] = $map['legacyToCanonical'][$ability];
        }

        // ชื่อ canonical -> ชื่อเก่าทุกตัวที่ map มาหามัน
        foreach ($map['canonicalToLegacy'][$ability] ?? [] as $legacy) {
            $names[] = $legacy;
        }

        return array_values(array_unique($names));
    }

    private function hasAllow(User $user, string $ability): bool
    {
        foreach ($this->equivalentNames($ability) as $name) {
            // hasPermissionTo() โยน exception เมื่อไม่มี permission แถวนั้นอยู่จริง
            // ซึ่งเกิดได้ตลอดในระบบนี้ เพราะโค้ดเช็คชื่อ canonical ที่ยังไม่ถูก seed
            // ถือว่า "ไม่มีสิทธิ์" ไม่ใช่ error
            try {
                if ($user->hasPermissionTo($name, 'web')) {
                    return true;
                }
            } catch (PermissionDoesNotExist) {
                continue;
            }
        }

        return false;
    }

    /**
     * Super Admin ยังผ่านได้ทุกอย่าง แต่ถูก explicit deny ปฏิเสธได้แล้ว (04-5)
     *
     * ยังคงอ้างชื่อ role ตรงนี้จุดเดียวโดยตั้งใจ เพราะเป็น bootstrap identity
     * ของระบบ — ถ้าผูกกับ permission จะเกิดปัญหาไก่กับไข่ตอนตั้งค่าครั้งแรก
     */
    private function isSuperAdmin(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'SuperAdmin']);
    }

    /** @return array{legacyToCanonical: array<string,string>, canonicalToLegacy: array<string,array<int,string>>} */
    private function aliases(): array
    {
        if ($this->aliasMap !== null) {
            return $this->aliasMap;
        }

        $legacyToCanonical = [];
        $canonicalToLegacy = [];

        if ($this->tableExists('permission_aliases')) {
            foreach (DB::table('permission_aliases')->get() as $row) {
                $legacyToCanonical[$row->legacy_name] = $row->canonical_name;
                $canonicalToLegacy[$row->canonical_name][] = $row->legacy_name;
            }
        }

        return $this->aliasMap = compact('legacyToCanonical', 'canonicalToLegacy');
    }

    /** ระหว่างรัน migration ตารางเหล่านี้อาจยังไม่มี */
    private function tableExists(string $table): bool
    {
        static $cache = [];

        return $cache[$table] ??= DB::getSchemaBuilder()->hasTable($table);
    }
}
