<?php

namespace App\Services\Workflow\Assignee;

use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowStep;
use App\Services\Workflow\ApproverResolverService;

/**
 * ผู้มีสิทธิ์อนุมัติใบลาในสายตาของ workflow engine
 *
 * ก่อนหน้านี้ engine แปลงขั้นตอนของใบลาเป็น `assignee_type` แบบหยาบ — ขั้นแบบ
 * "ผู้บังคับบัญชาชั้นต้น / หัวหน้าแผนก / รอง ผอ. / ผอ." ถูกยุบรวมเป็น `org_manager`
 * ตัวเดียวกันหมด ทำให้ engine ตอบว่า "หัวหน้าแผนกต้นสังกัด" มีสิทธิ์ในขั้นของผู้อำนวยการด้วย
 * และไม่มีทางถอยไปหาผู้ถือ permission เมื่อยังไม่ได้ตั้งผังหัวหน้าหน่วยงาน
 *
 * ตัวแปลนี้ตัดปัญหาที่ต้นเหตุ: อ่าน snapshot สายอนุมัติของใบลาใบนั้น (`leave_approvals`)
 * แล้วส่งให้ `ApproverResolverService` ตัวเดียวกับที่เส้นทางอนุมัติจริงใช้
 * คำตอบของ engine กับของระบบเดิมจึงตรงกันเสมอโดยไม่ต้องดูแลตรรกะสองชุด
 */
class LeaveAssigneeResolver implements SubjectAssigneeResolver
{
    public function __construct(private readonly ApproverResolverService $approvers) {}

    public function subjectType(): string
    {
        return (new Leave)->getMorphClass();
    }

    /**
     * @return array<int,int>|null
     */
    public function candidates(WorkflowInstance $instance, WorkflowStep $step): ?array
    {
        $leave = Leave::with('user')->find($instance->subject_id);

        if ($leave?->user === null) {
            return null;
        }

        $snapshot = LeaveApproval::query()
            ->where('leave_id', $leave->id)
            ->where('stage', LeaveApproval::STAGE_REQUEST)
            ->where('step_order', $step->step_order)
            ->first();

        if ($snapshot === null) {
            // ใบลาที่ยังไม่มี snapshot ของขั้นนี้ — ปล่อยให้ engine ใช้ตรรกะกลางไปก่อน
            return null;
        }

        return $this->approvers->resolveEligibleApproverIds($leave->user, $snapshot);
    }
}
