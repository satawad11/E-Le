<?php

namespace App\Services\Reporting;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\Overtime\OvertimeTimeRecord;
use App\Models\Saraban\Document;
use App\Models\User;
use App\Services\Modules\ModuleRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReportingDataService
{
    public const REPORTS = ['personnel', 'leave', 'leave_balance', 'attendance', 'late', 'absence', 'ot', 'saraban', 'executive'];

    private const SOURCE_MODULES = [
        'personnel' => 'system', 'leave' => 'leave', 'leave_balance' => 'leave',
        'attendance' => 'attendance', 'late' => 'attendance', 'absence' => 'attendance',
        'ot' => 'overtime', 'saraban' => 'saraban',
    ];

    public function __construct(private ReportingScopeService $scope, private ModuleRegistry $modules) {}

    public function build(string $report, ReportFilter $filter, User $viewer): ReportDataset
    {
        if ($report === 'executive') {
            return $this->executive($filter, $viewer);
        }

        abort_unless($this->modules->isEnabled(self::SOURCE_MODULES[$report] ?? ''), 404);

        $ability = $this->scope->authorize($viewer, $report);

        return match ($report) {
            'personnel' => $this->personnel($filter, $viewer, $ability),
            'leave' => $this->leave($filter, $viewer, $ability),
            'leave_balance' => $this->leaveBalance($filter, $viewer, $ability),
            'attendance' => $this->attendance($filter, $viewer, $ability),
            'late' => $this->late($filter, $viewer, $ability),
            'absence' => $this->absence($filter, $viewer, $ability),
            'ot' => $this->overtime($filter, $viewer, $ability),
            'saraban' => $this->saraban($filter, $viewer, $ability),
            default => throw new \InvalidArgumentException("Unknown report: {$report}"),
        };
    }

    private function personnel(ReportFilter $filter, User $viewer, string $ability): ReportDataset
    {
        $users = User::query()
            ->whereIn('users.id', $this->scope->users($viewer, $ability, $filter, true))
            ->with(['personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'currentAssignment.positionRef.group', 'orgUnit:id,name', 'department:id,name', 'personnelType:id,name', 'profile:id,user_id,position'])
            ->orderBy('users.name')
            ->get(['users.id', 'users.uuid', 'users.name', 'users.org_unit_id', 'users.department_id', 'users.personnel_type_id', 'users.is_active']);
        $rows = $users->map(fn (User $user): array => [
            'user_id' => $user->id,
            'uuid' => $user->uuid,
            'name' => $user->personnel?->full_name ?: $user->name,
            'position' => $user->currentAssignment?->position ?? $user->profile?->position,
            'organization' => $user->currentAssignment?->orgUnit?->name ?? $user->orgUnit?->name ?? $user->department?->name,
            'personnel_type' => $user->currentAssignment?->personnelType?->name ?? $user->personnelType?->name,
            'position_group' => $user->currentAssignment?->positionRef?->group?->name ?? 'ยังไม่ระบุ',
            'active' => $user->is_active,
        ])->all();

        return $this->dataset('personnel', 'Personnel Report', [
            'name' => 'Name', 'position' => 'Position', 'organization' => 'Organization',
            'personnel_type' => 'Personnel Type', 'position_group' => 'Personnel Group', 'active' => 'Active',
        ], $rows, ['personnel' => count($rows)], $filter);
    }

    private function leave(ReportFilter $filter, User $viewer, string $ability): ReportDataset
    {
        $rows = Leave::query()
            ->join('users', 'users.id', '=', 'leaves.user_id')
            ->join('leave_types', 'leave_types.id', '=', 'leaves.leave_type_id')
            ->leftJoin('org_units', 'org_units.id', '=', 'users.org_unit_id')
            ->leftJoin('personnel_types', 'personnel_types.id', '=', 'users.personnel_type_id')
            ->whereIn('leaves.user_id', $this->scope->users($viewer, $ability, $filter))
            ->where('leaves.status', 'Approved')
            ->whereBetween('leaves.start_date', [$filter->from, $filter->to])
            ->selectRaw('users.id as user_id, users.name, org_units.name as organization, personnel_types.name as personnel_type, leave_types.name as leave_type, COUNT(leaves.id) as leave_times, COALESCE(SUM(leaves.total_days), 0) as leave_days')
            ->groupBy('users.id', 'users.name', 'org_units.name', 'personnel_types.name', 'leave_types.id', 'leave_types.name')
            ->orderBy('users.name')->orderBy('leave_types.name')->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id, 'name' => $row->name,
                'organization' => $row->organization, 'personnel_type' => $row->personnel_type,
                'leave_type' => $row->leave_type, 'leave_times' => (int) $row->leave_times,
                'leave_days' => (float) $row->leave_days,
            ])->all();

        return $this->dataset('leave', 'Leave Report', [
            'name' => 'Name', 'organization' => 'Organization', 'personnel_type' => 'Personnel Type',
            'leave_type' => 'Leave Type', 'leave_times' => 'Times', 'leave_days' => 'Days',
        ], $rows, [
            'leave_times' => array_sum(array_column($rows, 'leave_times')),
            'leave_days' => array_sum(array_column($rows, 'leave_days')),
        ], $filter);
    }

    private function leaveBalance(ReportFilter $filter, User $viewer, string $ability): ReportDataset
    {
        $rows = LeaveBalance::query()
            ->join('users', 'users.id', '=', 'leave_balances.user_id')
            ->join('leave_types', 'leave_types.id', '=', 'leave_balances.leave_type_id')
            ->leftJoin('org_units', 'org_units.id', '=', 'users.org_unit_id')
            ->whereIn('leave_balances.user_id', $this->scope->users($viewer, $ability, $filter))
            ->where('leave_balances.year', $filter->fiscalYear)
            ->select(['users.id as user_id', 'users.name', 'org_units.name as organization', 'leave_types.name as leave_type', 'leave_balances.quota', 'leave_balances.carried_forward', 'leave_balances.used', 'leave_balances.reserved'])
            ->orderBy('users.name')->orderBy('leave_types.name')->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id, 'name' => $row->name,
                'organization' => $row->organization, 'leave_type' => $row->leave_type,
                'quota' => (float) $row->quota, 'carried_forward' => (float) $row->carried_forward,
                'used' => (float) $row->used, 'reserved' => (float) $row->reserved,
                'available' => (float) $row->quota + (float) $row->carried_forward - (float) $row->used - (float) $row->reserved,
            ])->all();

        return $this->dataset('leave_balance', 'Leave Balance Report', [
            'name' => 'Name', 'organization' => 'Organization', 'leave_type' => 'Leave Type',
            'quota' => 'Quota', 'carried_forward' => 'Carried', 'used' => 'Used',
            'reserved' => 'Reserved', 'available' => 'Available',
        ], $rows, ['available' => array_sum(array_column($rows, 'available'))], $filter);
    }

    private function attendance(ReportFilter $filter, User $viewer, string $ability): ReportDataset
    {
        $rows = $this->attendanceAggregate($filter, $viewer, $ability)->get()->map(fn ($row): array => [
            'user_id' => (int) $row->user_id, 'name' => $row->name, 'organization' => $row->organization,
            'recorded_days' => (int) $row->recorded_days, 'present_days' => (int) $row->present_days,
            'late_days' => (int) $row->late_days, 'absence_days' => (int) $row->absence_days,
            'late_minutes' => (int) $row->late_minutes, 'early_leave_minutes' => (int) $row->early_leave_minutes,
        ])->all();

        return $this->dataset('attendance', 'Attendance Report', [
            'name' => 'Name', 'organization' => 'Organization', 'recorded_days' => 'Recorded Days',
            'present_days' => 'Present', 'late_days' => 'Late', 'absence_days' => 'Absent',
            'late_minutes' => 'Late Minutes', 'early_leave_minutes' => 'Early Leave Minutes',
        ], $rows, [
            'recorded_days' => array_sum(array_column($rows, 'recorded_days')),
            'absence_days' => array_sum(array_column($rows, 'absence_days')),
        ], $filter);
    }

    private function late(ReportFilter $filter, User $viewer, string $ability): ReportDataset
    {
        $rows = $this->attendanceBase($filter, $viewer, $ability)
            ->where('attendances.late_minutes', '>', 0)
            ->selectRaw('users.id as user_id, users.name, org_units.name as organization, COUNT(attendances.id) as late_times, COALESCE(SUM(attendances.late_minutes), 0) as late_minutes')
            ->groupBy('users.id', 'users.name', 'org_units.name')->orderBy('users.name')->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id, 'name' => $row->name, 'organization' => $row->organization,
                'late_times' => (int) $row->late_times, 'late_minutes' => (int) $row->late_minutes,
            ])->all();

        return $this->dataset('late', 'Late Report', [
            'name' => 'Name', 'organization' => 'Organization', 'late_times' => 'Times', 'late_minutes' => 'Minutes',
        ], $rows, ['late_times' => array_sum(array_column($rows, 'late_times')), 'late_minutes' => array_sum(array_column($rows, 'late_minutes'))], $filter);
    }

    private function absence(ReportFilter $filter, User $viewer, string $ability): ReportDataset
    {
        $rows = $this->attendanceBase($filter, $viewer, $ability)
            ->where('attendances.status', 'Absent')
            ->selectRaw('users.id as user_id, users.name, org_units.name as organization, COUNT(attendances.id) as absence_days')
            ->groupBy('users.id', 'users.name', 'org_units.name')->orderBy('users.name')->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id, 'name' => $row->name,
                'organization' => $row->organization, 'absence_days' => (int) $row->absence_days,
            ])->all();

        return $this->dataset('absence', 'Absence Report', [
            'name' => 'Name', 'organization' => 'Organization', 'absence_days' => 'Absent Days',
        ], $rows, ['absence_days' => array_sum(array_column($rows, 'absence_days'))], $filter);
    }

    private function overtime(ReportFilter $filter, User $viewer, string $ability): ReportDataset
    {
        $approvedMinutes = DB::connection()->getDriverName() === 'sqlite'
            ? 'CAST((julianday(ot_orders.approved_end) - julianday(ot_orders.approved_start)) * 1440 AS INTEGER)'
            : 'TIMESTAMPDIFF(MINUTE, ot_orders.approved_start, ot_orders.approved_end)';
        $visible = $this->scope->visibleOrgUnitIds($viewer, $ability, $filter);
        $rows = OvertimeTimeRecord::query()
            ->join('users', 'users.id', '=', 'ot_time_records.user_id')
            ->join('ot_assignments', 'ot_assignments.id', '=', 'ot_time_records.ot_assignment_id')
            ->join('ot_orders', 'ot_orders.id', '=', 'ot_assignments.ot_order_id')
            ->leftJoin('org_units', 'org_units.id', '=', 'users.org_unit_id')
            ->whereIn('ot_time_records.user_id', $this->scope->users($viewer, $ability, $filter))
            ->when($visible !== null, fn (Builder $query) => $query->whereIn('ot_orders.org_unit_id', $visible))
            ->whereBetween('ot_time_records.actual_start', [$filter->from, $filter->to])
            ->selectRaw("users.id as user_id, users.name, org_units.name as organization, COUNT(ot_time_records.id) as assignments, COALESCE(SUM(CASE WHEN ot_orders.approved_start IS NOT NULL AND ot_orders.approved_end IS NOT NULL THEN {$approvedMinutes} ELSE 0 END), 0) as approved_minutes, COALESCE(SUM(ot_time_records.actual_minutes), 0) as actual_minutes, COALESCE(SUM(ot_time_records.certified_minutes), 0) as certified_minutes")
            ->groupBy('users.id', 'users.name', 'org_units.name')->orderBy('users.name')->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id, 'name' => $row->name, 'organization' => $row->organization,
                'assignments' => (int) $row->assignments, 'approved_minutes' => (int) $row->approved_minutes,
                'actual_minutes' => (int) $row->actual_minutes, 'certified_minutes' => (int) $row->certified_minutes,
            ])->all();

        return $this->dataset('ot', 'Overtime Report', [
            'name' => 'Name', 'organization' => 'Organization', 'assignments' => 'Assignments',
            'approved_minutes' => 'Approved Minutes', 'actual_minutes' => 'Actual Minutes',
            'certified_minutes' => 'Certified Minutes',
        ], $rows, [
            'approved_minutes' => array_sum(array_column($rows, 'approved_minutes')),
            'actual_minutes' => array_sum(array_column($rows, 'actual_minutes')),
            'certified_minutes' => array_sum(array_column($rows, 'certified_minutes')),
        ], $filter);
    }

    private function saraban(ReportFilter $filter, User $viewer, string $ability): ReportDataset
    {
        $query = Document::query()
            ->join('users', 'users.id', '=', 'saraban_documents.created_by')
            ->leftJoin('org_units', 'org_units.id', '=', 'users.org_unit_id')
            ->whereIn('saraban_documents.created_by', $this->scope->users($viewer, $ability, $filter))
            ->whereBetween('saraban_documents.created_at', [$filter->from, $filter->to])
            ->when(! $viewer->can('saraban.document.view_classified'), fn ($documents) => $documents->where('saraban_documents.secrecy', 'normal'));
        $rows = $query
            ->selectRaw('org_units.name as organization, saraban_documents.status, saraban_documents.priority, COUNT(saraban_documents.id) as documents')
            ->groupBy('org_units.name', 'saraban_documents.status', 'saraban_documents.priority')
            ->orderBy('org_units.name')->orderBy('saraban_documents.status')->get()
            ->map(fn ($row): array => [
                'organization' => $row->organization, 'status' => $row->status,
                'priority' => $row->priority, 'documents' => (int) $row->documents,
            ])->all();

        return $this->dataset('saraban', 'Saraban Report', [
            'organization' => 'Organization', 'status' => 'Status', 'priority' => 'Priority', 'documents' => 'Documents',
        ], $rows, ['documents' => array_sum(array_column($rows, 'documents'))], $filter);
    }

    private function executive(ReportFilter $filter, User $viewer): ReportDataset
    {
        $rows = [];
        foreach (ReportingScopeService::REPORT_ABILITIES as $report => $ability) {
            if ($this->modules->isEnabled(self::SOURCE_MODULES[$report]) && $this->scope->canView($viewer, $report)) {
                $dataset = $this->build($report, $filter, $viewer);
                foreach ($dataset->totals as $metric => $value) {
                    $rows[] = ['module' => $report, 'metric' => $metric, 'value' => $value];
                }
            }
        }
        if ($rows === []) {
            throw new AuthorizationException;
        }

        return $this->dataset('executive', 'Executive Summary', [
            'module' => 'Module', 'metric' => 'Metric', 'value' => 'Value',
        ], $rows, ['available_modules' => count(array_unique(array_column($rows, 'module')))], $filter);
    }

    private function attendanceBase(ReportFilter $filter, User $viewer, string $ability): Builder
    {
        return Attendance::query()
            ->join('users', 'users.id', '=', 'attendances.user_id')
            ->leftJoin('org_units', 'org_units.id', '=', 'users.org_unit_id')
            ->whereIn('attendances.user_id', $this->scope->users($viewer, $ability, $filter))
            ->whereBetween('attendances.date', [$filter->from->toDateString(), $filter->to->toDateString()]);
    }

    private function attendanceAggregate(ReportFilter $filter, User $viewer, string $ability): Builder
    {
        return $this->attendanceBase($filter, $viewer, $ability)
            ->selectRaw("users.id as user_id, users.name, org_units.name as organization, COUNT(attendances.id) as recorded_days, SUM(CASE WHEN attendances.status IN ('Present','Late') THEN 1 ELSE 0 END) as present_days, SUM(CASE WHEN attendances.late_minutes > 0 THEN 1 ELSE 0 END) as late_days, SUM(CASE WHEN attendances.status = 'Absent' THEN 1 ELSE 0 END) as absence_days, COALESCE(SUM(attendances.late_minutes), 0) as late_minutes, COALESCE(SUM(attendances.early_leave_minutes), 0) as early_leave_minutes")
            ->groupBy('users.id', 'users.name', 'org_units.name')->orderBy('users.name');
    }

    private function dataset(string $report, string $title, array $columns, array $rows, array $totals, ReportFilter $filter): ReportDataset
    {
        return new ReportDataset($report, $title, $columns, $rows, $totals, [
            ...$filter->meta(), 'generated_at' => now()->toISOString(), 'row_count' => count($rows),
        ]);
    }
}
