<?php

namespace App\Services\DocumentEngine\Providers;

use App\Models\Leave;
use App\Models\User;
use App\Services\DocumentEngine\DocumentDataProvider;
use App\Services\Reporting\LeaveReportCategoryResolver;
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

    public function __construct(
        private readonly ReportingScopeService $scope,
        private readonly LeaveReportCategoryResolver $categories,
    ) {}

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

        /*
         * REPORT-005 — แต่ละช่องของรายงานมาจากชุด leave_type_id ที่ resolver
         * ตัดสินจาก `leave_types.code_prefix` (ตั้งค่าได้ใน config/leave_reporting.php)
         * เดิมตรงนี้เป็น `leave_types.name LIKE '%ป่วย%'` ฝังไว้ใน SQL
         * การเปลี่ยนชื่อประเภทการลาจากหน้าผู้ดูแลจึงทำให้ยอดในรายงานหายไปเงียบ ๆ
         */
        $categories = $this->categories->labels();

        $query = Leave::query()
            ->join('users', 'users.id', '=', 'leaves.user_id')
            ->leftJoin('departments', 'departments.id', '=', 'users.department_id')
            ->whereIn('leaves.user_id', $this->scope->users($viewer, $ability, $filter, true))
            ->where('leaves.status', 'Approved')
            ->whereDate('leaves.start_date', '>=', $from->toDateString())
            ->whereDate('leaves.start_date', '<=', $to->toDateString())
            ->groupBy('users.id', 'users.name', 'departments.name')
            ->selectRaw('users.id as user_id, users.name as employee, departments.name as department');

        foreach (array_keys($categories) as $category) {
            $typeIds = $this->categories->typeIds($category);

            if ($typeIds === []) {
                $query->selectRaw('0 as '.$category);

                continue;
            }

            $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
            $query->selectRaw(
                "SUM(CASE WHEN leaves.leave_type_id IN ({$placeholders}) THEN leaves.total_days ELSE 0 END) as {$category}",
                $typeIds,
            );
        }

        $rows = $query
            ->selectRaw('SUM(leaves.total_days) as total_days')
            ->orderBy('departments.name')
            ->orderBy('users.name')
            ->get()
            ->map(function ($row) use ($categories) {
                $mapped = [
                    'employee' => $row->employee,
                    'department' => $row->department ?? '-',
                ];

                foreach (array_keys($categories) as $category) {
                    $mapped[$category] = (float) $row->{$category};
                }

                $mapped['total_days'] = (float) $row->total_days;

                return $mapped;
            })->all();

        $totals = [];
        foreach (array_keys($categories) as $category) {
            $totals[$category] = array_sum(array_column($rows, $category));
        }
        $totals['total_days'] = array_sum(array_column($rows, 'total_days'));

        return new ReportDataset(
            report: self::CODE,
            title: 'รายงานสถิติการลา',
            columns: array_merge([
                'employee' => 'ชื่อ-นามสกุล',
                'department' => 'แผนก/งาน',
            ], $categories, [
                'total_days' => 'รวม',
            ]),
            rows: $rows,
            totals: $totals,
            meta: ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        );
    }

    public function catalog(): array
    {
        $catalog = [
            ['code' => 'report.employee', 'label_th' => 'ชื่อ-นามสกุล', 'group_th' => 'รายงานสถิติการลา', 'data_type' => 'TEXT'],
            ['code' => 'report.department', 'label_th' => 'แผนก/งาน', 'group_th' => 'รายงานสถิติการลา', 'data_type' => 'TEXT'],
        ];

        // ช่องของรายงานมาจาก config เดียวกับ dataset() — เพิ่มกลุ่มใหม่ที่เดียวจบ
        foreach ($this->categories->labels() as $category => $label) {
            $catalog[] = [
                'code' => 'report.'.$category,
                'label_th' => 'จำนวนวัน'.$label,
                'group_th' => 'รายงานสถิติการลา',
                'data_type' => 'NUMBER',
            ];
        }

        $catalog[] = ['code' => 'report.total_days', 'label_th' => 'รวมจำนวนวันลา', 'group_th' => 'รายงานสถิติการลา', 'data_type' => 'NUMBER'];

        return $catalog;
    }
}
