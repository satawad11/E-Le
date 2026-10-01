<?php

namespace App\Policies;

use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\User;
use App\Services\OrgScopeResolver;
use Illuminate\Database\Eloquent\Builder;

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
        if ($leave->status === 'Draft') {
            return $this->isOwner($user, $leave);
        }

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

    /**
     * รูปแบบ query ของ `view()` — หรี่รายการใบลาให้เหลือฉบับที่ผู้ใช้เปิดดูได้
     *
     * ใช้กับหน้ารายการที่ต้องแบ่งหน้า (เช่น เอกสารที่ระบบออกให้ในศูนย์จัดการเอกสาร · DC-01)
     * ซึ่งกรองทีละฉบับด้วย `view()` ไม่ได้เพราะยอดรวมและหน้าจะเพี้ยน ลำดับเงื่อนไขตรงกับ `view()`:
     * เจ้าของ → ผู้ปฏิบัติงานแทน → ผู้อนุมัติที่มีชื่อ → permission ระดับองค์กร → ระดับหน่วยงาน
     *
     * ระดับหน่วยงานเทียบ `users.org_unit_id` ของเจ้าของใบลา (แบบเดียวกับ `LeaveVisibility`)
     * ส่วน `view()` ใช้หน่วยงานต้นสังกัดที่รวม personnel_assignments — การดาวน์โหลดจริง
     * จึงต้องผ่าน `download()` ทุกครั้ง query นี้มีไว้หรี่รายการเท่านั้น
     *
     * @param  Builder<Leave>  $leaves
     * @return Builder<Leave>
     */
    public function viewableQuery(Builder $leaves, User $user): Builder
    {
        // ผ่าน Gate (`can`) ไม่ใช่ hasAnyPermission ตรง ๆ — Super Admin และ explicit deny จึงมีผลเหมือนที่อื่น
        $organisationWide = collect(self::ORGANISATION_WIDE)->contains(fn (string $ability) => $user->can($ability));

        if ($organisationWide) {
            return $leaves->where(fn (Builder $query) => $query
                ->where('status', '!=', 'Draft')
                ->orWhere('user_id', $user->id));
        }

        $resolver = app(OrgScopeResolver::class);
        $departmentUnits = $user->can(self::DEPARTMENT_SCOPED) && $resolver->homeOrgUnitId($user) !== null
            ? $resolver->visibleOrgUnitIds($user, self::DEPARTMENT_SCOPED, OrgScopeResolver::UNIT)
            : [];

        return $leaves->where(function (Builder $query) use ($user, $departmentUnits) {
            $query->where('user_id', $user->id)
                ->orWhere(fn (Builder $others) => $others
                    ->where('status', '!=', 'Draft')
                    ->where(function (Builder $reach) use ($user, $departmentUnits) {
                        $reach->where('substitute_id', $user->id)
                            ->orWhereIn('id', LeaveApproval::query()->select('leave_id')->where('approver_id', $user->id));

                        if ($departmentUnits === null) {
                            $reach->orWhereNotNull('id');
                        } elseif ($departmentUnits !== []) {
                            $reach->orWhereIn('user_id', User::query()->select('id')->whereIn('org_unit_id', $departmentUnits));
                        }
                    }));
        });
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
     * เดิมมีทางถอยไปเทียบ `department_id` สำหรับข้อมูลที่ยัง backfill ไม่ถึง ·
     * ผังองค์กรเหลือชุดเดียวและคอลัมน์นั้นถูกถอดออกแล้ว ผู้ที่ยังไม่มีหน่วยงาน
     * จึงไม่ถือว่าอยู่หน่วยงานเดียวกับใคร ซึ่งเป็นผลแบบ fail-closed ที่ถูกต้อง
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

        return $ownerOrgUnit !== null
            && $viewerOrgUnit !== null
            && $resolver->canSeeOrgUnit($user, self::DEPARTMENT_SCOPED, $ownerOrgUnit);
    }
}
