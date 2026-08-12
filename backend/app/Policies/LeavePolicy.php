<?php

namespace App\Policies;

use App\Models\Leave;
use App\Models\User;

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
        if ($leave->approvals()->where('approver_id', $user->id)->exists()) {
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
     * เทียบหน่วยงานของผู้ใช้กับหน่วยงานของเจ้าของใบลา
     *
     * รองรับทั้งกรณีที่เก็บ department ไว้บน users และบน user_profiles
     * ตามที่โค้ดเดิมทำอยู่ — ถ้าฝั่งใดฝั่งหนึ่งไม่มีข้อมูล ให้ถือว่าไม่อยู่ใน scope
     */
    private function sharesDepartmentWith(User $user, Leave $leave): bool
    {
        $viewerDepartment = $user->department_id ?? $user->profile?->department_id;

        $owner = $leave->relationLoaded('user') ? $leave->user : $leave->user()->first();
        $ownerDepartment = $owner?->department_id ?? $owner?->profile?->department_id;

        return $viewerDepartment !== null
            && $ownerDepartment !== null
            && (int) $viewerDepartment === (int) $ownerDepartment;
    }
}
