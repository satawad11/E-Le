<?php

namespace App\Services\Reporting;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\Overtime\OvertimeTimeRecord;
use App\Models\User;
use App\Services\Modules\ModuleRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReportingDataService
{
    public const REPORTS = ['personnel', 'leave', 'leave_balance', 'attendance', 'late', 'absence', 'ot', 'executive'];

    private const SOURCE_MODULES = [
        'personnel' => 'system', 'leave' => 'leave', 'leave_balance' => 'leave',
        'attendance' => 'attendance', 'late' => 'attendance', 'absence' => 'attendance',
        'ot' => 'overtime',
    ];

    /**
     * ชื่อรายงานที่แสดงในสารบัญของศูนย์รายงานกลาง
     *
     * CLEANUP-01 (D3) — ชั้นรายงานกลางเดิมไม่มีหน้าจอใดเรียกใช้ ข้อความจึงยังเป็น
     * ภาษาอังกฤษทั้งชุด เมื่อยกให้เป็นชั้นรายงานหลักตามข้อตกลง จึงต้องเป็นไทย 100%
     * ตาม `AGENTS.md`
     */
    public const REPORT_TITLES = [
        'personnel' => 'รายงานข้อมูลบุคลากร',
        'leave' => 'รายงานสถิติการลา',
        'leave_balance' => 'รายงานสิทธิและวันลาคงเหลือ',
        'attendance' => 'รายงานการลงเวลาปฏิบัติงาน',
        'late' => 'รายงานการมาสาย',
        'absence' => 'รายงานการขาดงาน',
        'ot' => 'รายงานการปฏิบัติงานล่วงเวลา',
        'executive' => 'รายงานสรุปสำหรับผู้บริหาร',
    ];

    private const MODULE_LABELS = [
        'system' => 'ข้อมูลบุคลากร',
        'leave' => 'ระบบการลา',
        'attendance' => 'ระบบลงเวลา',
        'overtime' => 'การปฏิบัติงานล่วงเวลา',
    ];

    private const METRIC_LABELS = [
        'personnel' => 'จำนวนบุคลากร',
        'leave_times' => 'จำนวนครั้งที่ลา',
        'leave_days' => 'จำนวนวันลา',
        'available' => 'วันลาคงเหลือรวม',
        'recorded_days' => 'วันที่บันทึกเวลา',
        'absence_days' => 'จำนวนวันที่ขาด',
        'late_times' => 'จำนวนครั้งที่มาสาย',
        'late_minutes' => 'สายรวม (นาที)',
        'approved_minutes' => 'ล่วงเวลาที่อนุมัติ (นาที)',
        'actual_minutes' => 'ล่วงเวลาที่ปฏิบัติจริง (นาที)',
        'certified_minutes' => 'ล่วงเวลาที่รับรองแล้ว (นาที)',
        'available_modules' => 'จำนวนโมดูลที่มีข้อมูล',
    ];

    public function __construct(private ReportingScopeService $scope, private ModuleRegistry $modules) {}

    /** ผู้ใช้คนนี้เปิดรายงานนี้ได้หรือไม่ — ทั้งโมดูลต้องเปิดอยู่และต้องมีสิทธิ์ */
    public function isAvailableTo(string $report, User $viewer): bool
    {
        return $this->modules->isEnabled(self::SOURCE_MODULES[$report] ?? '')
            && $this->scope->canView($viewer, $report);
    }

    /** รายงานผู้บริหารมีความหมายต่อเมื่อมีรายงานต้นทางอย่างน้อยหนึ่งตัวที่เปิดดูได้ */
    public function hasAnyExecutiveSource(User $viewer): bool
    {
        foreach (array_keys(ReportingScopeService::REPORT_ABILITIES) as $report) {
            if ($this->isAvailableTo($report, $viewer)) {
                return true;
            }
        }

        return false;
    }

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
            default => throw new \InvalidArgumentException("Unknown report: {$report}"),
        };
    }

    private function personnel(ReportFilter $filter, User $viewer, string $ability): ReportDataset
    {
        $users = User::query()
            ->whereIn('users.id', $this->scope->users($viewer, $ability, $filter, true))
            ->with(['personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'orgUnit:id,name', 'department:id,name', 'personnelType:id,name', 'profile:id,user_id,position'])
            ->orderBy('users.name')
            ->get(['users.id', 'users.uuid', 'users.name', 'users.org_unit_id', 'users.org_unit_id', 'users.personnel_type_id', 'users.is_active']);
        $rows = $users->map(fn (User $user): array => [
            'user_id' => $user->id,
            'uuid' => $user->uuid,
            'name' => $user->personnel?->full_name ?: $user->name,
            'position' => $user->currentAssignment?->position ?? $user->profile?->position,
            'organization' => $user->currentAssignment?->orgUnit?->name ?? $user->orgUnit?->name ?? $user->department?->name,
            'personnel_type' => $user->currentAssignment?->personnelType?->name ?? $user->personnelType?->name,
            'active' => $user->is_active,
        ])->all();

        return $this->dataset('personnel', 'รายงานข้อมูลบุคลากร', [
            'name' => 'ชื่อ-สกุล', 'position' => 'ตำแหน่ง', 'organization' => 'หน่วยงาน',
            'personnel_type' => 'ประเภทบุคลากร', 'active' => 'สถานะใช้งาน',
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

        return $this->dataset('leave', 'รายงานสถิติการลา', [
            'name' => 'ชื่อ-สกุล', 'organization' => 'หน่วยงาน', 'personnel_type' => 'ประเภทบุคลากร',
            'leave_type' => 'ประเภทการลา', 'leave_times' => 'จำนวนครั้ง', 'leave_days' => 'จำนวนวัน',
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
            // ยอดวันลาเก็บด้วยเลข ค.ศ. ส่วนตัวกรองเป็น พ.ศ. — แปลงที่เดียวใน ReportFilter
            ->where('leave_balances.year', $filter->balanceYear())
            ->select(['users.id as user_id', 'users.name', 'org_units.name as organization', 'leave_types.name as leave_type', 'leave_balances.quota', 'leave_balances.carried_forward', 'leave_balances.used', 'leave_balances.reserved'])
            ->orderBy('users.name')->orderBy('leave_types.name')->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id, 'name' => $row->name,
                'organization' => $row->organization, 'leave_type' => $row->leave_type,
                'quota' => (float) $row->quota, 'carried_forward' => (float) $row->carried_forward,
                'used' => (float) $row->used, 'reserved' => (float) $row->reserved,
                'available' => (float) $row->quota + (float) $row->carried_forward - (float) $row->used - (float) $row->reserved,
            ])->all();

        return $this->dataset('leave_balance', 'รายงานสิทธิและวันลาคงเหลือ', [
            'name' => 'ชื่อ-สกุล', 'organization' => 'หน่วยงาน', 'leave_type' => 'ประเภทการลา',
            'quota' => 'สิทธิ', 'carried_forward' => 'ยกมา', 'used' => 'ใช้ไป',
            'reserved' => 'กันไว้', 'available' => 'คงเหลือ',
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

        return $this->dataset('attendance', 'รายงานการลงเวลาปฏิบัติงาน', [
            'name' => 'ชื่อ-สกุล', 'organization' => 'หน่วยงาน', 'recorded_days' => 'วันที่บันทึก',
            'present_days' => 'มาปฏิบัติงาน', 'late_days' => 'มาสาย (วัน)', 'absence_days' => 'ขาดงาน (วัน)',
            'late_minutes' => 'สายรวม (นาที)', 'early_leave_minutes' => 'กลับก่อนรวม (นาที)',
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

        return $this->dataset('late', 'รายงานการมาสาย', [
            'name' => 'ชื่อ-สกุล', 'organization' => 'หน่วยงาน', 'late_times' => 'จำนวนครั้ง', 'late_minutes' => 'รวม (นาที)',
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

        return $this->dataset('absence', 'รายงานการขาดงาน', [
            'name' => 'ชื่อ-สกุล', 'organization' => 'หน่วยงาน', 'absence_days' => 'จำนวนวันที่ขาด',
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

        return $this->dataset('ot', 'รายงานการปฏิบัติงานล่วงเวลา', [
            'name' => 'ชื่อ-สกุล', 'organization' => 'หน่วยงาน', 'assignments' => 'จำนวนครั้งที่ปฏิบัติ',
            'approved_minutes' => 'อนุมัติ (นาที)', 'actual_minutes' => 'ปฏิบัติจริง (นาที)',
            'certified_minutes' => 'รับรองแล้ว (นาที)',
        ], $rows, [
            'approved_minutes' => array_sum(array_column($rows, 'approved_minutes')),
            'actual_minutes' => array_sum(array_column($rows, 'actual_minutes')),
            'certified_minutes' => array_sum(array_column($rows, 'certified_minutes')),
        ], $filter);
    }

    private function executive(ReportFilter $filter, User $viewer): ReportDataset
    {
        $rows = [];
        foreach (ReportingScopeService::REPORT_ABILITIES as $report => $ability) {
            if ($this->modules->isEnabled(self::SOURCE_MODULES[$report]) && $this->scope->canView($viewer, $report)) {
                $dataset = $this->build($report, $filter, $viewer);
                foreach ($dataset->totals as $metric => $value) {
                    $rows[] = [
                        'module' => self::MODULE_LABELS[self::SOURCE_MODULES[$report]] ?? $report,
                        'metric' => self::METRIC_LABELS[$metric] ?? $metric,
                        'value' => $value,
                    ];
                }
            }
        }
        if ($rows === []) {
            throw new AuthorizationException;
        }

        return $this->dataset('executive', 'รายงานสรุปสำหรับผู้บริหาร', [
            'module' => 'โมดูล', 'metric' => 'ตัวชี้วัด', 'value' => 'ค่า',
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
        return new ReportDataset(
            $report,
            $title,
            $columns,
            $rows,
            $totals,
            [...$filter->meta(), 'generated_at' => now()->toISOString(), 'row_count' => count($rows)],
            // ยอดรวมบางตัวไม่มีคอลัมน์คู่กัน (เช่น `personnel`) ถ้าไม่มีชื่อไทยกำกับ
            // หน้าเว็บกับ PDF จะพิมพ์ชื่อคีย์ภาษาอังกฤษออกมาให้ผู้ใช้เห็น
            array_intersect_key(self::METRIC_LABELS, $totals),
        );
    }
}
