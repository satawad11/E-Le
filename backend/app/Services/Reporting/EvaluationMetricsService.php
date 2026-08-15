<?php

namespace App\Services\Reporting;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Overtime\OvertimeTimeRecord;
use App\Models\User;
use App\Services\Modules\ModuleRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class EvaluationMetricsService
{
    public function __construct(private ReportingScopeService $scope, private ModuleRegistry $modules) {}

    /**
     * Returns evidence metrics only. It intentionally has no score, threshold,
     * pass/fail, weight, ranking, or compensation rule.
     */
    public function build(ReportFilter $filter, User $viewer): ReportDataset
    {
        abort_unless($this->modules->isEnabled('leave') && $this->modules->isEnabled('attendance'), 404);
        if (! $viewer->can('leave.report.view') || ! $viewer->can('attendance.view_all')) {
            throw new AuthorizationException;
        }

        $users = User::query()
            ->whereIn('users.id', $this->scope->users($viewer, 'leave.report.view', $filter, true))
            ->whereIn('users.id', $this->scope->users($viewer, 'attendance.view_all', $filter, true))
            ->with(['orgUnit:id,name', 'department:id,name', 'personnelType:id,name'])
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.org_unit_id', 'users.department_id', 'users.personnel_type_id']);
        $ids = $users->pluck('id');

        $leave = Leave::query()
            ->whereIn('user_id', $ids)->where('status', 'Approved')
            ->whereBetween('start_date', [$filter->from, $filter->to])
            ->selectRaw('user_id, COUNT(id) as leave_times, COALESCE(SUM(total_days), 0) as leave_days')
            ->groupBy('user_id')->get()->keyBy('user_id');
        $attendance = Attendance::query()
            ->whereIn('user_id', $ids)
            ->whereBetween('date', [$filter->from->toDateString(), $filter->to->toDateString()])
            ->selectRaw("user_id, COUNT(id) as attendance_records, SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late_times, COALESCE(SUM(late_minutes), 0) as late_minutes, SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absence_days")
            ->groupBy('user_id')->get()->keyBy('user_id');

        $overtime = collect();
        if ($viewer->can('overtime.management.review')) {
            $visible = $this->scope->visibleOrgUnitIds($viewer, 'overtime.management.review', $filter);
            $approvedMinutes = DB::connection()->getDriverName() === 'sqlite'
                ? 'CAST((julianday(ot_orders.approved_end) - julianday(ot_orders.approved_start)) * 1440 AS INTEGER)'
                : 'TIMESTAMPDIFF(MINUTE, ot_orders.approved_start, ot_orders.approved_end)';
            $overtime = OvertimeTimeRecord::query()
                ->join('ot_assignments', 'ot_assignments.id', '=', 'ot_time_records.ot_assignment_id')
                ->join('ot_orders', 'ot_orders.id', '=', 'ot_assignments.ot_order_id')
                ->whereIn('ot_time_records.user_id', $ids)
                ->when($visible !== null, fn ($query) => $query->whereIn('ot_orders.org_unit_id', $visible))
                ->whereBetween('ot_time_records.actual_start', [$filter->from, $filter->to])
                ->selectRaw("ot_time_records.user_id, COALESCE(SUM(CASE WHEN ot_orders.approved_start IS NOT NULL AND ot_orders.approved_end IS NOT NULL THEN {$approvedMinutes} ELSE 0 END), 0) as ot_approved_minutes, COALESCE(SUM(ot_time_records.actual_minutes), 0) as ot_actual_minutes, COALESCE(SUM(ot_time_records.certified_minutes), 0) as ot_certified_minutes")
                ->groupBy('ot_time_records.user_id')->get()->keyBy('user_id');
        }

        $rows = $users->map(function (User $user) use ($leave, $attendance, $overtime): array {
            $leaveMetric = $leave->get($user->id);
            $attendanceMetric = $attendance->get($user->id);
            $otMetric = $overtime->get($user->id);

            return [
                'user_id' => $user->id,
                'name' => $user->name,
                'organization' => $user->orgUnit?->name ?? $user->department?->name,
                'personnel_type' => $user->personnelType?->name,
                'leave_times' => (int) ($leaveMetric->leave_times ?? 0),
                'leave_days' => (float) ($leaveMetric->leave_days ?? 0),
                'attendance_records' => (int) ($attendanceMetric->attendance_records ?? 0),
                'late_times' => (int) ($attendanceMetric->late_times ?? 0),
                'late_minutes' => (int) ($attendanceMetric->late_minutes ?? 0),
                'absence_days' => (int) ($attendanceMetric->absence_days ?? 0),
                'ot_approved_minutes' => (int) ($otMetric->ot_approved_minutes ?? 0),
                'ot_actual_minutes' => (int) ($otMetric->ot_actual_minutes ?? 0),
                'ot_certified_minutes' => (int) ($otMetric->ot_certified_minutes ?? 0),
            ];
        })->all();

        return new ReportDataset('evaluation_metrics', 'Evaluation Evidence Metrics', [
            'name' => 'Name', 'organization' => 'Organization', 'personnel_type' => 'Personnel Type',
            'leave_times' => 'Leave Times', 'leave_days' => 'Leave Days',
            'attendance_records' => 'Attendance Records', 'late_times' => 'Late Times',
            'late_minutes' => 'Late Minutes', 'absence_days' => 'Absence Days',
            'ot_approved_minutes' => 'OT Approved Minutes', 'ot_actual_minutes' => 'OT Actual Minutes',
            'ot_certified_minutes' => 'OT Certified Minutes',
        ], $rows, [], [...$filter->meta(), 'generated_at' => now()->toISOString(), 'row_count' => count($rows), 'metrics_only' => true, 'formula' => null]);
    }
}
