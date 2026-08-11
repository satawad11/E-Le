<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * รายงานคัดกรองเกณฑ์การเลื่อนเงินเดือน
 *
 * รอบการประเมินตามระเบียบ: รอบที่ 1 = 1 ต.ค. – 31 มี.ค. / รอบที่ 2 = 1 เม.ย. – 30 ก.ย.
 * เกณฑ์ที่ตรวจให้ (ปรับค่าได้ทุกตัวเพราะแต่ละส่วนราชการกำหนดไม่เท่ากัน):
 *   - วันลาที่นับได้เกินเกณฑ์ (ค่าเริ่มต้น 23 วันทำการ)
 *   - จำนวนครั้งที่มาสายเกินเกณฑ์ (ค่าเริ่มต้น 18 ครั้ง)
 *   - เวลาปฏิบัติราชการในรอบไม่ครบ (ค่าเริ่มต้น 4 เดือน)
 *
 * การลาที่ไม่นับรวมกำหนดที่ leave_types.counts_toward_promotion
 */
class PromotionScreeningController extends Controller
{
    public function __construct(private \App\Services\PersonalDataAccessLogger $accessLog) {}

    private const DEFAULT_MAX_LEAVE_DAYS = 23;
    private const DEFAULT_MAX_LATE_TIMES = 18;
    private const DEFAULT_MIN_SERVICE_MONTHS = 4;

    private function authorizeAccess(Request $request): void
    {
        abort_unless(
            $request->user()->can('leave.report.view') || $request->user()->can('leave.management.manage'),
            403,
            'ไม่มีสิทธิ์เข้าถึงรายงานนี้'
        );
    }

    /** ช่วงวันที่ของรอบการประเมิน — คืนค่าเป็น [เริ่ม, สิ้นสุด, ชื่อรอบ] */
    private function roundPeriod(int $fiscalYear, int $round): array
    {
        return $round === 1
            ? [Carbon::create($fiscalYear - 1, 10, 1)->startOfDay(), Carbon::create($fiscalYear, 3, 31)->endOfDay(), 'ครั้งที่ 1 (1 ต.ค. – 31 มี.ค.)']
            : [Carbon::create($fiscalYear, 4, 1)->startOfDay(), Carbon::create($fiscalYear, 9, 30)->endOfDay(), 'ครั้งที่ 2 (1 เม.ย. – 30 ก.ย.)'];
    }

    private function build(Request $request): array
    {
        $this->authorizeAccess($request);

        $data = $request->validate([
            'year'            => 'nullable|integer|min:2000|max:2100',
            'round'           => 'nullable|integer|in:1,2',
            'department_id'   => 'nullable|exists:departments,id',
            'personnel_type_id' => 'nullable|exists:personnel_types,id',
            'max_leave_days'  => 'nullable|numeric|min:0',
            'max_late_times'  => 'nullable|integer|min:0',
            'min_service_months' => 'nullable|integer|min:0',
        ]);

        $fiscalYear = (int) ($data['year'] ?? (now()->month >= 10 ? now()->year + 1 : now()->year));
        $round = (int) ($data['round'] ?? (now()->month >= 4 && now()->month <= 9 ? 2 : 1));
        [$from, $to, $roundLabel] = $this->roundPeriod($fiscalYear, $round);

        $maxLeaveDays = (float) ($data['max_leave_days'] ?? self::DEFAULT_MAX_LEAVE_DAYS);
        $maxLateTimes = (int) ($data['max_late_times'] ?? self::DEFAULT_MAX_LATE_TIMES);
        $minServiceMonths = (int) ($data['min_service_months'] ?? self::DEFAULT_MIN_SERVICE_MONTHS);

        $countedTypeIds = LeaveType::where('counts_toward_promotion', true)->pluck('id')->all();

        $users = User::with(['profile:id,user_id,position,start_date', 'department:id,name', 'personnelType:id,name'])
            ->where('is_active', true)
            ->when($data['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($data['personnel_type_id'] ?? null, fn ($q, $v) => $q->where('personnel_type_id', $v))
            ->orderBy('name')
            ->get();

        // ดึงสถิติทั้งรอบครั้งเดียวแล้วค่อยจับคู่ เพื่อไม่ให้เกิด query ต่อผู้ใช้หนึ่งคน
        $leaveStats = Leave::selectRaw('user_id, leave_type_id, COUNT(*) as times, SUM(total_days) as days')
            ->where('status', 'Approved')
            ->whereBetween('start_date', [$from, $to])
            ->groupBy('user_id', 'leave_type_id')
            ->get()
            ->groupBy('user_id');

        $lateStats = Attendance::selectRaw('user_id, COUNT(*) as times, SUM(late_minutes) as minutes')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('late_minutes', '>', 0)
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $rows = $users->map(function (User $user) use ($leaveStats, $lateStats, $countedTypeIds, $from, $to, $maxLeaveDays, $maxLateTimes, $minServiceMonths) {
            $stats = $leaveStats->get($user->id, collect());
            $counted = $stats->whereIn('leave_type_id', $countedTypeIds);
            $excluded = $stats->whereNotIn('leave_type_id', $countedTypeIds);

            $leaveDays = (float) $counted->sum('days');
            $leaveTimes = (int) $counted->sum('times');
            $late = $lateStats->get($user->id);
            $lateTimes = (int) ($late->times ?? 0);

            // เวลาปฏิบัติราชการในรอบ: ผู้ที่บรรจุกลางรอบจะนับจากวันบรรจุ
            $serviceStart = $user->profile?->start_date ? Carbon::parse($user->profile->start_date) : null;
            $effectiveStart = $serviceStart && $serviceStart->gt($from) ? $serviceStart : $from;
            $serviceMonths = $effectiveStart->gt($to) ? 0 : $effectiveStart->diffInMonths($to);

            $flags = [];
            if ($leaveDays > $maxLeaveDays) $flags[] = "ลาเกินเกณฑ์ ({$leaveDays} วัน)";
            if ($lateTimes > $maxLateTimes) $flags[] = "มาสายเกินเกณฑ์ ({$lateTimes} ครั้ง)";
            if ($serviceMonths < $minServiceMonths) $flags[] = "เวลาปฏิบัติราชการไม่ครบ ({$serviceMonths} เดือน)";

            return [
                'user_id'        => $user->id,
                'name'           => $user->name,
                'position'       => $user->profile?->position ?? '—',
                'department'     => $user->department?->name ?? '—',
                'personnel_type' => $user->personnelType?->name ?? '—',
                'leave_times'    => $leaveTimes,
                'leave_days'     => $leaveDays,
                'excluded_days'  => (float) $excluded->sum('days'),
                'late_times'     => $lateTimes,
                'late_minutes'   => (int) ($late->minutes ?? 0),
                'service_months' => $serviceMonths,
                'flags'          => $flags,
                'passed'         => $flags === [],
            ];
        })->values();

        return [
            'fiscal_year'    => $fiscalYear,
            'fiscal_year_th' => $fiscalYear + 543,
            'round'          => $round,
            'round_label'    => $roundLabel,
            'period'         => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'criteria'       => [
                'max_leave_days' => $maxLeaveDays,
                'max_late_times' => $maxLateTimes,
                'min_service_months' => $minServiceMonths,
                'excluded_leave_types' => LeaveType::where('counts_toward_promotion', false)->pluck('name'),
            ],
            'totals'         => ['all' => $rows->count(), 'flagged' => $rows->where('passed', false)->count()],
            'rows'           => $rows,
        ];
    }

    public function index(Request $request)
    {
        $result = $this->build($request);
        $this->accessLog->recordBulk($request, 'promotion_screening', count($result['rows']), 'เปิดรายงานคัดกรองเกณฑ์เลื่อนเงินเดือน');

        return response()->json($result);
    }

    public function csv(Request $request)
    {
        $result = $this->build($request);
        $this->accessLog->recordBulk($request, 'promotion_screening_csv', count($result['rows']), 'ส่งออกรายงานคัดกรองเกณฑ์เลื่อนเงินเดือนเป็นไฟล์ CSV');

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['ชื่อ-สกุล', 'ตำแหน่ง', 'สังกัด', 'ประเภทบุคลากร', 'ลา (ครั้ง)', 'ลาที่นับได้ (วัน)', 'ลาที่ไม่นับ (วัน)', 'มาสาย (ครั้ง)', 'สายรวม (นาที)', 'เวลาปฏิบัติราชการ (เดือน)', 'ผลการคัดกรอง', 'หมายเหตุ']);
        foreach ($result['rows'] as $row) {
            fputcsv($out, [
                $row['name'], $row['position'], $row['department'], $row['personnel_type'],
                $row['leave_times'], $row['leave_days'], $row['excluded_days'],
                $row['late_times'], $row['late_minutes'], $row['service_months'],
                $row['passed'] ? 'ผ่านเกณฑ์' : 'ต้องพิจารณา',
                implode(' / ', $row['flags']),
            ]);
        }
        rewind($out);
        $content = stream_get_contents($out);
        fclose($out);

        $filename = "promotion-screening-{$result['fiscal_year_th']}-r{$result['round']}.csv";
        return response("\xEF\xBB\xBF" . $content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
