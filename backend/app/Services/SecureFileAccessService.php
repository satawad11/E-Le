<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Gate;

/**
 * ใครเปิดไฟล์บน private storage ได้บ้าง
 *
 * SEC-029 — ให้ไฟล์ใช้ตัวตัดสินสิทธิ์ตัวเดียวกับทั้งระบบ
 *
 * ก่อนแก้ บริการนี้ตัดสินด้วย `$user->hasAnyPermission([...])` ของ Spatie ตรง ๆ
 * ซึ่งข้ามชั้นตัดสินกลางทั้งหมด:
 *
 *   - **explicit deny ไม่มีผล** — ผู้ที่ถูกปฏิเสธสิทธิ์ไว้อย่างชัดแจ้ง
 *     (`permission_denies`) ยังเปิดไฟล์ได้ ทั้งที่ทุก endpoint อื่นปฏิเสธเขาแล้ว
 *   - **ไม่รู้จักชื่อที่เทียบเท่ากัน** — ฐานข้อมูลจริงยังถือชื่อเก่าอยู่หลายรายการ
 *     ต้องไล่เขียนชื่อเก่าคู่กับชื่อ canonical เองทุกจุด และตกหล่นได้ง่าย
 *   - **ไม่มี organization scope** — ผู้ดูแลที่ถูกจำกัดขอบเขตไว้ที่หน่วยงานตนเอง
 *     เปิดรูปประจำตัวและลายเซ็นของบุคลากรทั้งสถานศึกษาได้
 *
 * ตอนนี้ทุกการตัดสินสิทธิ์ผ่าน `PermissionEvaluator` (deny > allow > default deny
 * พร้อมเทียบชื่อข้ามรุ่น) และขอบเขตหน่วยงานถาม `OrgScopeResolver`
 * ส่วนการเข้าถึงไฟล์ของใบลายังใช้ `LeavePolicy` ผ่าน `Gate` ตามเดิม
 *
 * ไฟล์กำพร้า (ไม่มีระเบียนใดอ้างถึง) ยังคงถูกปฏิเสธเหมือนเดิมโดยปริยาย
 */
class SecureFileAccessService
{
    public function __construct(
        private OrgScopeResolver $scopes,
        private PermissionEvaluator $evaluator,
    ) {}

    public function canView(User $user, string $relative): bool
    {
        $stored = '/storage/'.$relative;

        if ($this->isOwnProfileAsset($user, $stored)) {
            return true;
        }

        if ($this->isManagedProfileAsset($user, $stored)) {
            return true;
        }

        if ($this->isAccessibleAttendanceImage($user, $stored)) {
            return true;
        }

        if ($this->isAccessibleLeaveAsset($user, $stored)) {
            return true;
        }

        return Setting::query()
            ->whereIn('key', ['organization_logo'])
            ->where('value', $stored)
            ->exists();
    }

    private function isOwnProfileAsset(User $user, string $stored): bool
    {
        return UserProfile::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query
                ->where('profile_photo_path', $stored)
                ->orWhere('signature_path', $stored))
            ->exists();
    }

    /**
     * ผู้ดูแลทะเบียนบุคลากรเปิดรูป/ลายเซ็นของผู้อื่นได้ — เฉพาะในขอบเขตที่ได้รับ
     */
    private function isManagedProfileAsset(User $user, string $stored): bool
    {
        $ability = $this->firstAllowed($user, ['system.user.manage', 'system.user.view']);

        if ($ability === null) {
            return false;
        }

        $ownerId = UserProfile::query()
            ->where(fn ($query) => $query
                ->where('profile_photo_path', $stored)
                ->orWhere('signature_path', $stored))
            ->value('user_id');

        if ($ownerId === null) {
            return false;
        }

        return $this->withinScope($user, $ability, (int) $ownerId);
    }

    private function isAccessibleAttendanceImage(User $user, string $stored): bool
    {
        $attendance = Attendance::query()
            ->where(fn ($query) => $query
                ->where('check_in_image', $stored)
                ->orWhere('check_out_image', $stored))
            ->first();

        if (! $attendance) {
            return false;
        }

        if ((int) $attendance->user_id === (int) $user->id) {
            return true;
        }

        $ability = $this->firstAllowed($user, ['attendance.view_all']);

        return $ability !== null && $this->withinScope($user, $ability, (int) $attendance->user_id);
    }

    private function isAccessibleLeaveAsset(User $user, string $stored): bool
    {
        $leave = Leave::query()
            ->where(fn ($query) => $query
                ->where('signature_path', $stored)
                ->orWhere('attachment', $stored))
            ->first();

        if (! $leave) {
            $leave = LeaveApproval::query()
                ->where('signature_path', $stored)
                ->first()
                ?->leave;
        }

        return $leave !== null && Gate::forUser($user)->allows('view', $leave);
    }

    /**
     * สิทธิ์ตัวแรกในรายการที่ผู้ใช้ "ได้รับจริง" ตามตัวตัดสินกลาง
     *
     * ถาม `PermissionEvaluator` ตรง ๆ แทนที่จะผ่าน `$user->can()`
     *
     * เหตุผล: Spatie ลงทะเบียน `Gate::before` ของตัวเองไว้ด้วย และ package provider
     * boot ก่อน `AppServiceProvider` เสมอ เมื่อผู้ใช้ "ถือ" สิทธิ์นั้นอยู่จริง
     * before ของ Spatie จะคืน `true` ก่อนที่ before ของเราจะได้ตรวจ explicit deny
     * (เทสต์ `PermissionEvaluatorTest::test_explicit_deny_beats_an_allow` ยืนยัน
     * ข้อจำกัดนี้ไว้แล้ว โดยตรวจผ่าน evaluator ไม่ใช่ผ่าน `can()`)
     *
     * การเรียก evaluator ตรงจึงได้ครบทั้ง explicit deny · ชื่อที่เทียบเท่ากัน · Super Admin
     *
     * @param  array<int, string>  $abilities
     */
    private function firstAllowed(User $user, array $abilities): ?string
    {
        foreach ($abilities as $ability) {
            if ($this->evaluator->allows($user, $ability)) {
                return $ability;
            }
        }

        return null;
    }

    /** เจ้าของไฟล์อยู่ในขอบเขตหน่วยงานที่ผู้ใช้รายนี้ได้รับสิทธิ์หรือไม่ */
    private function withinScope(User $user, string $ability, int $ownerId): bool
    {
        if ($ownerId === (int) $user->id) {
            return true;
        }

        $owner = User::query()->select('id', 'org_unit_id')->find($ownerId);

        if ($owner === null) {
            return false;
        }

        return $this->scopes->canSeeOrgUnit($user, $ability, $owner->org_unit_id, OrgScopeResolver::ORGANIZATION);
    }
}
