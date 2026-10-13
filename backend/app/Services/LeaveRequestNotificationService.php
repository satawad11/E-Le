<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\User;
use App\Services\NotificationCenter\NotificationCenter;
use Illuminate\Support\Facades\Log;

class LeaveRequestNotificationService
{
    public function __construct(
        private NotificationCenter $notifications,
        private LeaveApprovalFlow $flow,
    ) {}

    /**
     * แจ้งผู้ยื่นว่าส่งคำขอแล้ว และแจ้งผู้อนุมัติ **เฉพาะขั้นแรก**
     *
     * เดิมส่งหาทุกคนที่มี role ผู้บริหารตามรายชื่อที่ hard-code ไว้ ทุกขั้นพร้อมกัน
     * ผู้อำนวยการจึงได้แจ้งเตือนทุกใบตั้งแต่ยังไม่ถึงคิวตน
     */
    public function notifyApprovers(Leave $leave): void
    {
        $this->safeEmit('leave.submitted', [$leave->user], $leave, $leave->user);
        $this->notifyCurrentStep($leave, 'leave.approval_requested', $leave->user);

        if ($leave->substitute_id && $leave->substitute) {
            $this->safeEmit('leave.substitute_requested', [$leave->substitute], $leave, $leave->user);
        }
    }

    /** แจ้งผู้มีสิทธิ์ในขั้นปัจจุบันของใบลา (ใช้ทั้งตอนเลื่อนขั้นอนุมัติและขอยกเลิก) */
    public function notifyCurrentStep(Leave $leave, string $type, ?User $actor = null): void
    {
        $recipients = $this->flow->currentApprovers($leave);
        if ($recipients->isEmpty()) {
            return;
        }
        $this->safeEmit($type, $recipients, $leave, $actor);
    }

    public function notifyRequester(Leave $leave, string $type, ?User $actor = null): void
    {
        if ($leave->user) {
            $this->safeEmit($type, [$leave->user], $leave, $actor);
        }
    }

    /** การแจ้งเตือนต้องไม่ทำให้ธุรกรรมการลาล้ม */
    private function safeEmit(string $type, iterable $recipients, Leave $leave, ?User $actor): void
    {
        try {
            $this->notifications->emit($type, $recipients, $leave, ['status' => $leave->status], $actor);
        } catch (\Throwable $exception) {
            Log::warning('ส่งแจ้งเตือนการลาไม่สำเร็จ', ['type' => $type, 'leave_id' => $leave->id, 'error' => $exception->getMessage()]);
        }
    }
}
