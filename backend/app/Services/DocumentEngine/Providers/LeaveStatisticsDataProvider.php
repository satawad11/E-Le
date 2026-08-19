<?php

namespace App\Services\DocumentEngine\Providers;

use App\Models\Leave;
use App\Models\User;
use App\Services\DocumentEngine\DocumentDataProvider;
use App\Services\Reporting\ReportDataset;
use App\Services\Reporting\ReportFilter;
use App\Services\Reporting\ReportingScopeService;
use Illuminate\Support\Carbon;

/**
 * สถิติการลารายบุคคลสำหรับรายงาน (DATASET)
 *
 * ใช้ `ReportingScopeService` ตัวเดียวกับ reporting layer เดิม ขอบเขตการมองเห็น
 * ของผู้เรียกจึงเป็นกติกาเดียวกันทั้งระบบ ไม่มีทางลัดเฉพาะรายงานใหม่
 */
class LeaveStatisticsDataProvider implements DocumentDataProvider
{
    public const CODE = 'LEAVE_MONTHLY_SUMMARY';

    public function __construct(private readonly ReportingScopeService $scope) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function module(): string
    {
        return 'leave';
    }

    public function fields(array $context): array
    {
        return $this->dataset($context)->toArray();
    }

    /**
     * @param  array{viewer?: User, filter?: ReportFilter, from?: string, to?: string}  $context
     */
    public function dataset(array $context): ReportDataset
    {
        $viewer = $context['viewer'] ?? null;

        if (! $viewer instanceof User) {
            throw new \InvalidArgumentException('รายงานต้องระบุผู้เรียกเพื่อตรวจขอบเขตการมองเห็น');
        }

        $from = isset($context['from']) ? Carbon::parse($context['from']) : Carbon::now()->startOfMonth();
        $to = isset($context['to']) ? Carbon::parse($context['to']) : Carbon::now()->endOfMonth();

        $filter = $context['filter'] ?? ReportFilter::from([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
        $ability = $this->scope->authorize($viewer, 'leave');

        $rows = Leave::query()
            ->join('users', 'users.id', '=', 'leaves.user_id')
            ->join('leave_types', 'leave_types.id', '=', 'leaves.leave_type_id')
            ->leftJoin('departments', 'departments.id', '=', 'users.department_id')
            ->whereIn('leaves.user_id', $this->scope->users($viewer, $ability, $filter, true))
            ->where('leaves.status', 'Approved')
            ->whereDate('leaves.start_date', '>=', $from->toDateString())
            ->whereDate('leaves.start_date', '<=', $to->toDateString())
            ->groupBy('users.id', 'users.name', 'departments.name')
            ->selectRaw('users.id as user_id, users.name as employee, departments.name as department')
            ->selectRaw("SUM(CASE WHEN leave_types.name LIKE '%ป่วย%' THEN leaves.total_days ELSE 0 END) as sick_days")
            ->selectRaw("SUM(CASE WHEN leave_types.name LIKE '%กิจ%' THEN leaves.total_days ELSE 0 END) as personal_days")
            ->selectRaw("SUM(CASE WHEN leave_types.name LIKE '%พักผ่อน%' THEN leaves.total_days ELSE 0 END) as vacation_days")
            ->selectRaw('SUM(leaves.total_days) as total_days')
            ->orderBy('departments.name')
            ->orderBy('users.name')
            ->get()
            ->map(fn ($row) => [
                'employee' => $row->employee,
                'department' => $row->department ?? '-',
                'sick_days' => (float) $row->sick_days,
                'personal_days' => (float) $row->personal_days,
                'vacation_days' => (float) $row->vacation_days,
                'total_days' => (float) $row->total_days,
            ])->all();

        return new ReportDataset(
            report: self::CODE,
            title: 'รายงานสถิติการลา',
            columns: [
                'employee' => 'ชื่อ-นามสกุล',
                'department' => 'แผนก/งาน',
                'sick_days' => 'ลาป่วย',
                'personal_days' => 'ลากิจส่วนตัว',
                'vacation_days' => 'ลาพักผ่อน',
                'total_days' => 'รวม',
            ],
            rows: $rows,
            totals: [
                'sick_days' => array_sum(array_column($rows, 'sick_days')),
                'personal_days' => array_sum(array_column($rows, 'personal_days')),
                'vacation_days' => array_sum(array_column($rows, 'vacation_days')),
                'total_days' => array_sum(array_column($rows, 'total_days')),
            ],
            meta: ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        );
    }

    public function catalog(): array
    {
        return [
            ['code' => 'report.employee', 'label_th' => 'ชื่อ-นามสกุล', 'group_th' => 'รายงานสถิติการลา', 'data_type' => 'TEXT'],
            ['code' => 'report.department', 'label_th' => 'แผนก/งาน', 'group_th' => 'รายงานสถิติการลา', 'data_type' => 'TEXT'],
            ['code' => 'report.sick_days', 'label_th' => 'จำนวนวันลาป่วย', 'group_th' => 'รายงานสถิติการลา', 'data_type' => 'NUMBER'],
            ['code' => 'report.personal_days', 'label_th' => 'จำนวนวันลากิจส่วนตัว', 'group_th' => 'รายงานสถิติการลา', 'data_type' => 'NUMBER'],
            ['code' => 'report.vacation_days', 'label_th' => 'จำนวนวันลาพักผ่อน', 'group_th' => 'รายงานสถิติการลา', 'data_type' => 'NUMBER'],
            ['code' => 'report.total_days', 'label_th' => 'รวมจำนวนวันลา', 'group_th' => 'รายงานสถิติการลา', 'data_type' => 'NUMBER'],
        ];
    }
}
