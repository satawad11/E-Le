<?php

namespace App\Services\Reporting;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\User;
use App\Services\OrgScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

class VocationalLeaveEvaluationService
{
    public const THRESHOLD_GOVERNMENT_OFFICER = 23.0;

    public const THRESHOLD_CONTRACT_EMPLOYEE = 13.0;

    public function __construct(
        private readonly OrgScopeResolver $orgScope,
    ) {}

    /**
     * Compute date range for standard Thai civil service evaluation cycles.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label: string}
     */
    public function resolveCycleDateRange(string $cycle, ?int $buddhistYear = null): array
    {
        $currentYear = $buddhistYear !== null ? $buddhistYear - 543 : (int) now()->year;

        return match ($cycle) {
            'round_1' => [
                'from' => CarbonImmutable::create($currentYear - 1, 10, 1)->startOfDay(),
                'to' => CarbonImmutable::create($currentYear, 3, 31)->endOfDay(),
                'label' => 'รอบที่ ๑ (๑ ต.ค. - ๓๑ มี.ค.)',
            ],
            'round_2' => [
                'from' => CarbonImmutable::create($currentYear, 4, 1)->startOfDay(),
                'to' => CarbonImmutable::create($currentYear, 9, 30)->endOfDay(),
                'label' => 'รอบที่ ๒ (๑ เม.ย. - ๓๐ ก.ย.)',
            ],
            default => [
                'from' => CarbonImmutable::create($currentYear - 1, 10, 1)->startOfDay(),
                'to' => CarbonImmutable::create($currentYear, 9, 30)->endOfDay(),
                'label' => 'ปีงบประมาณ '.($currentYear + 543),
            ],
        };
    }

    /**
     * Generate personal performance & leave evidence pack for an individual user (Self-Service).
     *
     * @return array{cycle: array{from: string, to: string, label: string}, evidence: array<string, mixed>}
     */
    public function getPersonalEvidence(User $user, ?int $buddhistYear = null): array
    {
        $dateRange = $this->resolveCycleDateRange('fiscal_year', $buddhistYear);
        $from = $dateRange['from'];
        $to = $dateRange['to'];

        $leaves = Leave::query()
            ->where('user_id', $user->id)
            ->where('status', 'Approved')
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('start_date', [$from->toDateString(), $to->toDateString()])
                    ->orWhereBetween('end_date', [$from->toDateString(), $to->toDateString()]);
            })
            ->with('type:id,name,code_prefix,attendance_bucket')
            ->get(['id', 'user_id', 'leave_type_id', 'total_days', 'start_date', 'end_date']);

        $sickDays = 0.0;
        $personalDays = 0.0;
        $vacationDays = 0.0;
        $otherDays = 0.0;
        $totalCountable = 0.0;

        foreach ($leaves as $leave) {
            $typePrefix = (string) ($leave->type?->code_prefix ?? '');
            $bucket = (string) ($leave->type?->attendance_bucket ?? '');
            $typeName = (string) ($leave->type?->name ?? '');
            $days = (float) $leave->total_days;

            if ($bucket === 'sick' || $typePrefix === 'ลป.' || str_contains(strtolower($typePrefix), 'sick') || str_contains($typeName, 'ป่วย')) {
                $sickDays += $days;
                $totalCountable += $days;
            } elseif ($bucket === 'personal' || in_array($typePrefix, ['ลก.', 'ลช.'], true) || str_contains(strtolower($typePrefix), 'personal') || str_contains($typeName, 'กิจ')) {
                $personalDays += $days;
                $totalCountable += $days;
            } elseif ($bucket === 'vacation' || $typePrefix === 'ลพ.' || str_contains(strtolower($typePrefix), 'vacation') || str_contains($typeName, 'พักผ่อน')) {
                $vacationDays += $days;
            } else {
                $otherDays += $days;
            }
        }

        $att = Attendance::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("COUNT(id) as total_attendance, SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late_times, COALESCE(SUM(late_minutes), 0) as late_minutes, SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absence_days")
            ->first();

        $lateTimes = (int) ($att->late_times ?? 0);
        $lateMinutes = (int) ($att->late_minutes ?? 0);
        $absenceDays = (int) ($att->absence_days ?? 0);

        $personnelTypeName = $user->personnelType?->name ?? '';
        $isGovOfficer = str_contains($personnelTypeName, 'ข้าราชการ') || str_contains($personnelTypeName, 'ครู');
        $maxThreshold = $isGovOfficer ? self::THRESHOLD_GOVERNMENT_OFFICER : self::THRESHOLD_CONTRACT_EMPLOYEE;
        $isExceeded = $totalCountable > $maxThreshold;

        $orgNames = $this->orgScope->homeOrgUnitNames($user);

        return [
            'cycle' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $dateRange['label'],
            ],
            'evidence' => [
                'user_id' => $user->id,
                'name' => $user->name,
                'division_name' => $orgNames['parent'],
                'department_name' => $orgNames['unit'],
                'personnel_type' => $personnelTypeName,
                'sick_days' => $sickDays,
                'personal_days' => $personalDays,
                'vacation_days' => $vacationDays,
                'other_days' => $otherDays,
                'total_countable_days' => $totalCountable,
                'leave_count' => count($leaves),
                'late_times' => $lateTimes,
                'late_minutes' => $lateMinutes,
                'absence_days' => $absenceDays,
                'max_threshold_days' => $maxThreshold,
                'threshold_exceeded' => $isExceeded,
                'evaluation_status' => $isExceeded ? 'เกินเกณฑ์วันลา' : 'ปกติ (ผ่านเกณฑ์)',
            ],
        ];
    }

    /**
     * Generate evaluation summary report scoped by user permissions and org units.
     *
     * @return array{cycle: string, date_range: array{from: string, to: string, label: string}, records: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function getEvaluationSummary(User $viewer, string $cycle = 'round_1', ?int $buddhistYear = null, ?int $orgUnitId = null): array
    {
        // 1. Authorization check: Viewer must have report view or scope permission
        if (! $viewer->can('leave.report.view') && ! $viewer->can('leave.management.manage')) {
            throw new AuthorizationException('ไม่มีสิทธิ์เข้าถึงรายงานการประเมินผลการปฏิบัติราชการ');
        }

        $dateRange = $this->resolveCycleDateRange($cycle, $buddhistYear);
        $from = $dateRange['from'];
        $to = $dateRange['to'];

        // 2. Resolve visible users based on OrgScopeResolver
        $visibleUnitIds = $this->orgScope->visibleOrgUnitIds($viewer, 'leave.report.view', OrgScopeResolver::ORGANIZATION);

        $usersQuery = User::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))
            ->with(['orgUnit:id,name,parent_id', 'personnelType:id,name']);

        if ($orgUnitId !== null) {
            // Check if viewer has access to this specific unit
            if ($visibleUnitIds !== null && ! in_array($orgUnitId, $visibleUnitIds, true)) {
                throw new AuthorizationException('ไม่มีสิทธิ์เข้าถึงข้อมูลของหน่วยงานที่ระบุ');
            }
            $subtreeIds = $this->orgScope->subtreeIds($orgUnitId);
            $usersQuery->whereIn('org_unit_id', $subtreeIds);
        } elseif ($visibleUnitIds !== null) {
            $usersQuery->whereIn('org_unit_id', $visibleUnitIds);
        }

        $users = $usersQuery->orderBy('name')->get(['id', 'name', 'org_unit_id', 'personnel_type_id', 'email']);
        $userIds = $users->pluck('id')->all();

        // 3. Aggregate Approved Leaves in the cycle
        $leaves = Leave::query()
            ->whereIn('user_id', $userIds)
            ->where('status', 'Approved')
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('start_date', [$from->toDateString(), $to->toDateString()])
                    ->orWhereBetween('end_date', [$from->toDateString(), $to->toDateString()]);
            })
            ->with('type:id,name,code_prefix,attendance_bucket')
            ->get(['id', 'user_id', 'leave_type_id', 'total_days', 'start_date', 'end_date']);

        // Group leaves by user and classify
        $leavesByUser = [];
        foreach ($leaves as $leave) {
            $uId = (int) $leave->user_id;
            if (! isset($leavesByUser[$uId])) {
                $leavesByUser[$uId] = [
                    'sick_days' => 0.0,
                    'personal_days' => 0.0,
                    'vacation_days' => 0.0,
                    'other_days' => 0.0,
                    'total_countable_days' => 0.0,
                    'leave_count' => 0,
                ];
            }

            $typePrefix = (string) ($leave->type?->code_prefix ?? '');
            $bucket = (string) ($leave->type?->attendance_bucket ?? '');
            $typeName = (string) ($leave->type?->name ?? '');
            $days = (float) $leave->total_days;

            $leavesByUser[$uId]['leave_count']++;

            if ($bucket === 'sick' || $typePrefix === 'ลป.' || str_contains(strtolower($typePrefix), 'sick') || str_contains($typeName, 'ป่วย')) {
                $leavesByUser[$uId]['sick_days'] += $days;
                $leavesByUser[$uId]['total_countable_days'] += $days;
            } elseif ($bucket === 'personal' || in_array($typePrefix, ['ลก.', 'ลช.'], true) || str_contains(strtolower($typePrefix), 'personal') || str_contains($typeName, 'กิจ')) {
                $leavesByUser[$uId]['personal_days'] += $days;
                $leavesByUser[$uId]['total_countable_days'] += $days;
            } elseif ($bucket === 'vacation' || $typePrefix === 'ลพ.' || str_contains(strtolower($typePrefix), 'vacation') || str_contains($typeName, 'พักผ่อน')) {
                $leavesByUser[$uId]['vacation_days'] += $days;
            } else {
                $leavesByUser[$uId]['other_days'] += $days;
            }
        }

        // 4. Aggregate Attendances (Late & Absent)
        $attendances = Attendance::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("user_id, COUNT(id) as total_attendance, SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late_times, COALESCE(SUM(late_minutes), 0) as late_minutes, SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absence_days")
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        // 5. Build results with civil service threshold verification
        $records = [];
        $totalExceeded = 0;

        foreach ($users as $user) {
            $uId = (int) $user->id;
            $leaveStats = $leavesByUser[$uId] ?? [
                'sick_days' => 0.0,
                'personal_days' => 0.0,
                'vacation_days' => 0.0,
                'other_days' => 0.0,
                'total_countable_days' => 0.0,
                'leave_count' => 0,
            ];

            $att = $attendances->get($uId);
            $lateTimes = (int) ($att->late_times ?? 0);
            $lateMinutes = (int) ($att->late_minutes ?? 0);
            $absenceDays = (int) ($att->absence_days ?? 0);

            // Determine threshold based on personnel classification
            $personnelTypeName = $user->personnelType?->name ?? '';
            $isGovOfficer = str_contains($personnelTypeName, 'ข้าราชการ') || str_contains($personnelTypeName, 'ครู');
            $maxThreshold = $isGovOfficer ? self::THRESHOLD_GOVERNMENT_OFFICER : self::THRESHOLD_CONTRACT_EMPLOYEE;

            $totalCountable = $leaveStats['total_countable_days'];
            $isExceeded = $totalCountable > $maxThreshold;

            if ($isExceeded) {
                $totalExceeded++;
            }

            $divisionId = $user->org_unit_id !== null ? $this->orgScope->divisionOrgUnitId((int) $user->org_unit_id) : null;
            $orgNames = $this->orgScope->homeOrgUnitNames($user);

            $records[] = [
                'user_id' => $uId,
                'name' => $user->name,
                'division_id' => $divisionId,
                'division_name' => $orgNames['parent'],
                'department_name' => $orgNames['unit'],
                'personnel_type' => $personnelTypeName,
                'sick_days' => $leaveStats['sick_days'],
                'personal_days' => $leaveStats['personal_days'],
                'vacation_days' => $leaveStats['vacation_days'],
                'other_days' => $leaveStats['other_days'],
                'total_countable_days' => $totalCountable,
                'leave_count' => $leaveStats['leave_count'],
                'late_times' => $lateTimes,
                'late_minutes' => $lateMinutes,
                'absence_days' => $absenceDays,
                'max_threshold_days' => $maxThreshold,
                'threshold_exceeded' => $isExceeded,
                'evaluation_status' => $isExceeded ? 'เกินเกณฑ์วันลา' : 'ปกติ (ผ่านเกณฑ์)',
            ];
        }

        return [
            'cycle' => $cycle,
            'date_range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $dateRange['label'],
            ],
            'records' => $records,
            'summary' => [
                'total_personnel' => count($records),
                'total_exceeded' => $totalExceeded,
                'total_normal' => count($records) - $totalExceeded,
                'exceeded_count' => $totalExceeded,
                'passed_count' => count($records) - $totalExceeded,
            ],
        ];
    }
}
