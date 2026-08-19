<?php

namespace App\Policies;

use App\Models\Leave;
use App\Models\User;
use App\Services\OrgScopeResolver;

/**
 * สิทธิ์การเข้าถึงใบลารายฉบับ (SEC-003 / SEC-004)
 *
 * เดิม endpoint พิมพ์ใบลาและรับรองสถิติมีแค่ auth:sanctum ไม่ตรวจ ownership เลย
 * ผู้ใช้ที่ล็อกอินคนใดก็ได้จึงเปิดใบลาของคนอื่นได้ ทั้งที่มีเหตุผลการลาป่วย
 * ที่อยู่ติดต่อ และลายเซ็นอยู่ในเอกสาร
 *
 * นโยบายนี้ใช้ **permission เท่านั้น** ไม่อ้างอิงชื่อ role
 * (ชื่อ role ในระบบนี้เป็นภาษาไทยที่ผู้ดูแลแก้ได้จากหน้าจอ การผูกสิทธิ์กับชื่อ role
 * ทำให้การเปลี่ยนชื่อเพียงครั้งเดียวทำลายการควบคุมสิทธิ์ทั้งระบบ)
 *
 * หมายเหตุ: Super Admin ผ่านได้ทุกข้อผ่าน Gate::before ใน AppServiceProvider
 */
class LeavePolicy
{
    /**
     * permission ที่ให้เห็นใบลาได้ทั้งองค์กร
     *
     * อิงจากชุดเดิมที่ LeaveController::index ใช้ตัดสิน "ดูได้ทั้งหมด"
     * เพื่อรักษาพฤติกรรมของผู้ใช้ที่เคยได้รับอนุญาตอยู่แล้ว
     */
    private const ORGANISATION_WIDE = [
        'leave.management.manage',
        'leave.approval.hr',
        'leave.approval.final',
        'leave.report.view',
    ];

    /** permission ที่ให้เห็นใบลาเฉพาะในหน่วยงานของตน */
    private const DEPARTMENT_SCOPED = 'leave.approval.dept';

    /** permission ที่ให้รับรองสถิติการลา (HR verification) */
    private const HR_VERIFY = [
        'leave.approval.hr',
        'leave.management.manage',
    ];

    /**
     * เปิด/พิมพ์ใบลาฉบับนี้ได้หรือไม่
     *
     * ลำดับการตัดสิน: เจ้าของ → ผู้ปฏิบัติงานแทน → ผู้อนุมัติที่ถูกมอบหมาย
     * → permission ระดับองค์กร → permission ระดับหน่วยงาน (ต้องอยู่หน่วยงานเดียวกัน)
     */
    public function view(User $user, Leave $leave): bool
    {
        if ($this->isOwner($user, $leave)) {
            return true;
        }

        // ผู้ปฏิบัติงานแทนต้องเห็นรายละเอียดงานที่ตนรับมอบหมาย
        if ($leave->substitute_id !== null && (int) $leave->substitute_id === (int) $user->id) {
            return true;
        }

        // ผู้อนุมัติที่มีชื่ออยู่ในสายอนุมัติของใบลาฉบับนี้โดยเฉพาะ
        // นับทั้งสายขอลาและสายขอยกเลิก — ผู้ที่ต้องพิจารณาคำขอยกเลิกก็ต้องเปิดใบลาได้
        if ($leave->allApprovals()->where('approver_id', $user->id)->exists()) {
            return true;
        }

        if ($user->hasAnyPermission(self::ORGANISATION_WIDE)) {
            return true;
        }

        if ($user->can(self::DEPARTMENT_SCOPED)) {
            return $this->sharesDepartmentWith($user, $leave);
        }

        return false;
    }

    /** ดาวน์โหลดไฟล์เอกสารของใบลา ใช้เกณฑ์เดียวกับการเปิดดู */
    public function download(User $user, Leave $leave): bool
    {
        return $this->view($user, $leave);
    }

    /**
     * รับรองสถิติการลา (SEC-004)
     *
     * ต้องมี permission ฝั่ง server เท่านั้น การอยู่หน่วยงานเดียวกันหรือเป็น
     * เจ้าของใบลาไม่ใช่เหตุผลให้รับรองได้
     */
    public function hrVerify(User $user, Leave $leave): bool
    {
        return $user->hasAnyPermission(self::HR_VERIFY);
    }

    private function isOwner(User $user, Leave $leave): bool
    {
        return (int) $leave->user_id === (int) $user->id;
    }

    /**
     * เจ้าของใบลาอยู่ในขอบเขตหน่วยงานที่ผู้ใช้ดูแลหรือไม่
     *
     * LEGACY-04 (04-3): เดิมเทียบ `department_id` ตรง ๆ ซึ่งเห็นได้แค่ระดับเดียว
     * ตอนนี้ถามผ่าน OrgScopeResolver ที่ไต่ต้นไม้ `org_units` ได้
     *
     * ค่าเริ่มต้นเมื่อไม่ได้กำหนดขอบเขตไว้คือ UNIT ซึ่งให้ผลเท่ากับโค้ดเดิม
     * ผู้ดูแลจึงขยายเป็น DESCENDANTS ให้บางคนได้ภายหลังโดยไม่ต้องแก้โค้ด
     *
     * ถ้ายังไม่มีข้อมูล org_unit (เช่นข้อมูลเก่าที่ยัง backfill ไม่ถึง)
     * จะถอยไปเทียบ `department_id` แบบเดิมเพื่อไม่ให้ผู้ใช้เสียสิทธิ์
     */
    private function sharesDepartmentWith(User $user, Leave $leave): bool
    {
        $owner = $leave->relationLoaded('user') ? $leave->user : $leave->user()->first();

        if ($owner === null) {
            return false;
        }

        $resolver = app(OrgScopeResolver::class);
        $ownerOrgUnit = $resolver->homeOrgUnitId($owner);
        $viewerOrgUnit = $resolver->homeOrgUnitId($user);

        if ($ownerOrgUnit !== null && $viewerOrgUnit !== null) {
            return $resolver->canSeeOrgUnit($user, self::DEPARTMENT_SCOPED, $ownerOrgUnit);
        }

        // ข้อมูลยังไม่มี org_unit — ใช้วิธีเดิม
        $viewerDepartment = $user->department_id ?? $user->profile?->department_id;
        $ownerDepartment = $owner->department_id ?? $owner->profile?->department_id;

        return $viewerDepartment !== null
            && $ownerDepartment !== null
            && (int) $viewerDepartment === (int) $ownerDepartment;
    }
}
