<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Models\Workflow\WorkflowTransition;
use App\Services\AuditLogger;
use App\Services\NotificationCenter\NotificationCenter;
use App\Services\Workflow\AttendanceCorrectionWorkflowProvisioner;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class AttendanceCorrectionService
{
    private const EDITABLE = ['check_in_time', 'check_out_time', 'status', 'notes'];

    public function __construct(
        private readonly AttendanceScopeService $scope,
        private readonly AttendanceCorrectionWorkflowProvisioner $provisioner,
        private readonly WorkflowEngine $workflow,
        private readonly AuditLogger $audit,
        private readonly NotificationCenter $notifications,
    ) {}

    public function request(Attendance $attendance, User $actor, array $changes, string $reason): AttendanceCorrection
    {
        if (! $this->scope->canSee($actor, $attendance->user)) {
            throw new AuthorizationException;
        }

        $changes = collect($changes)->only(self::EDITABLE)->all();

        return DB::transaction(function () use ($attendance, $actor, $changes, $reason) {
            $before = collect($attendance->getAttributes())->only(array_keys($changes))->all();
            $correction = AttendanceCorrection::create([
                'attendance_id' => $attendance->getKey(),
                'user_id' => $attendance->user_id,
                'requested_by' => $actor->getKey(),
                'before_values' => $before,
                'proposed_values' => $changes,
                'reason' => $reason,
                'status' => 'pending',
            ]);

            $instance = $this->provisioner->start($correction);
            $this->audit->log('attendance.manual_correction.requested', $correction, null, $before, $changes, $reason, $actor->getKey());

            $reviewers = User::permission('attendance.view_all')->get()
                ->filter(fn (User $reviewer) => $this->scope->canSee($reviewer, $attendance->user));
            $this->notifications->emit('attendance.correction', $reviewers, $correction, [
                'status' => 'pending',
            ], $actor);

            return $correction->setRelation('workflowInstance', $instance);
        }, 5);
    }

    public function decide(AttendanceCorrection $correction, User $actor, string $action, ?string $comment = null): AttendanceCorrection
    {
        if (! $actor->can('attendance.view_all') || ! $this->scope->canSee($actor, $correction->attendance->user)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($correction, $actor, $action, $comment) {
            $correction = AttendanceCorrection::whereKey($correction->getKey())->lockForUpdate()->firstOrFail();

            if ($correction->status !== 'pending') {
                return $correction;
            }

            $instance = $this->workflow->instanceFor($correction);
            if ($instance === null) {
                throw new \RuntimeException('ไม่พบ workflow ของคำขอแก้ไขข้อมูลลงเวลา');
            }

            $this->workflow->perform($instance, $action, $actor, $comment);

            if ($action === WorkflowTransition::APPROVE) {
                $attendance = Attendance::whereKey($correction->attendance_id)->lockForUpdate()->firstOrFail();
                $before = collect($attendance->getAttributes())->only(array_keys($correction->proposed_values))->all();
                $attendance->fill($correction->proposed_values)->save();
                $correction->update(['status' => 'approved', 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'applied_at' => now()]);
                $this->audit->log('attendance.manual_correction.applied', $attendance, null, $before, $correction->proposed_values, $correction->reason, $actor->getKey());
            } else {
                $correction->update(['status' => 'rejected', 'decided_by' => $actor->getKey(), 'decided_at' => now()]);
                $this->audit->log('attendance.manual_correction.rejected', $correction, null, null, ['comment' => $comment], $correction->reason, $actor->getKey());
            }

            $this->notifications->emit('attendance.correction', [$correction->user], $correction, [
                'status' => $correction->status,
            ], $actor);

            return $correction->refresh();
        }, 5);
    }
}
