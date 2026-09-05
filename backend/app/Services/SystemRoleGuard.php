<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\SystemRoles;
use Illuminate\Support\Facades\DB;

/**
 * SEC-026 — กันระบบเหลือศูนย์ผู้ดูแล
 *
 * ระบบต้องมีบัญชีที่ยังใช้งานได้อย่างน้อยหนึ่งบัญชีที่ถือบทบาทผู้ดูแลระบบสูงสุด
 * เสมอ มิฉะนั้นจะไม่มีใครแก้การตั้งค่าใด ๆ กลับได้อีกเลยโดยไม่แตะฐานข้อมูลตรง ๆ
 *
 * ด่านนี้ครอบสามทางที่ทำให้ผู้ดูแลคนสุดท้ายหายไป:
 *   1. เปลี่ยนบทบาทของตัวเอง/ของคนอื่นไปเป็นบทบาทอื่น (self-lockout)
 *   2. ลบบัญชีผู้ดูแลคนสุดท้าย
 *   3. ระงับการใช้งานบัญชีผู้ดูแลคนสุดท้าย
 *
 * อ้างถึงบทบาทด้วย `system_key` ไม่ใช่ชื่อที่แสดง (ชื่อแก้ได้จากหน้าจอ)
 */
class SystemRoleGuard
{
    /**
     * ยังเหลือผู้ดูแลระบบสูงสุดที่ใช้งานได้อยู่หรือไม่ หากตัดผู้ใช้รายนี้ออก
     */
    public function anotherActiveSuperAdminExists(User $excluding): bool
    {
        $role = Role::superAdmin();

        if ($role === null) {
            // ระบบที่ยังไม่ได้ติดคีย์ระบบ — ไม่มีข้อมูลพอจะตัดสิน จึงไม่ขวาง
            return true;
        }

        return DB::table('model_has_roles')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.role_id', $role->id)
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('users.id', '!=', $excluding->id)
            ->where('users.is_active', true)
            ->exists();
    }

    /** ผู้ใช้รายนี้เป็นผู้ดูแลระบบสูงสุดที่ใช้งานได้คนสุดท้ายหรือไม่ */
    public function isLastActiveSuperAdmin(User $user): bool
    {
        return $this->holdsSuperAdmin($user)
            && $user->is_active
            && ! $this->anotherActiveSuperAdminExists($user);
    }

    /**
     * การเปลี่ยนบทบาทของผู้ใช้รายนี้เป็น `$newRoleId` จะทำให้ระบบไม่เหลือผู้ดูแลหรือไม่
     */
    public function roleChangeWouldRemoveLastSuperAdmin(User $user, ?int $newRoleId): bool
    {
        if (! $this->isLastActiveSuperAdmin($user)) {
            return false;
        }

        $superAdmin = Role::superAdmin();

        return $superAdmin === null ? false : (int) $newRoleId !== (int) $superAdmin->id;
    }

    private function holdsSuperAdmin(User $user): bool
    {
        $role = Role::superAdmin();

        return $role !== null && $user->roles()->whereKey($role->id)->exists();
    }

    /** ข้อความภาษาไทยที่ใช้ตอบผู้ใช้เมื่อชนด่านนี้ */
    public function blockedMessage(): string
    {
        return 'ไม่สามารถดำเนินการได้ เพราะระบบจะไม่เหลือผู้ดูแลระบบสูงสุดที่ใช้งานได้ '
            .'กรุณามอบบทบาทผู้ดูแลระบบสูงสุดให้บัญชีอื่นที่ยังใช้งานอยู่ก่อน';
    }

    /** คีย์ระบบของบทบาทผู้ดูแลระบบสูงสุด — ใช้อ้างอิงในเทสต์และรายงาน */
    public function superAdminKey(): string
    {
        return SystemRoles::SUPER_ADMIN;
    }
}
