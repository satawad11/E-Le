<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\User;
use App\Services\NotificationCenter\NotificationCenter;

class LeaveRequestNotificationService
{
    public function __construct(private NotificationCenter $notifications) {}

    public function notifyApprovers(Leave $leave): void
    {
        $approvers = User::where(function ($q) {
            $q->whereHas('roles', function ($query) {
                $query->whereIn('name', [
                    'ผู้ดูแลระบบสูงสุด', 'Super Admin',
                    'ผู้อำนวยการ', 'Director',
                    'รองผู้อำนวยการ', 'Deputy Director',
                    'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย', 'Head of Department',
                    'เจ้าหน้าที่บุคลากร (HR)', 'HR Head',
                ]);
            })->orWhereHas('permissions', function ($query) {
                $query->whereIn('name', ['leave.approval.dept', 'leave.approval.director', 'approve_leave', 'approve_leaves']);
            });
        })->get();

        $this->notifications->emit(
            'leave.submitted', [$leave->user], $leave,
            ['status' => $leave->status], $leave->user,
        );
        $this->notifications->emit(
            'leave.approval_requested', $approvers, $leave,
            ['status' => $leave->status], $leave->user,
        );
    }
}
