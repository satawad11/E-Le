<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\User;
use App\Services\LeavePolicyService;
use App\Services\PersonalDataAccessLogger;
use App\Services\ThaiDateService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * บัตรลา — สรุปสถิติการลารายบุคคลประจำปีงบประมาณ
 * ใช้แนบประกอบการพิจารณาเลื่อนเงินเดือนและเก็บเข้าแฟ้มประวัติ
 */
class LeaveCardController extends Controller
{
    public function __construct(
        private LeavePolicyService $policies,
        private PersonalDataAccessLogger $accessLog,
    ) {}

    /**
     * ผู้ใช้ดูบัตรลาของตนเองได้เสมอ ส่วนของคนอื่นต้องมีสิทธิ์ดูรายงาน
     * หัวหน้าแผนก/ฝ่ายดูได้เฉพาะคนในหน่วยงานตนเอง
     */
    private function resolveTarget(Request $request): User
    {
        $viewer = $request->user();
        $targetId = (int) $request->input('user_id', $viewer->id);

        if ($targetId === $viewer->id) {
            return $viewer->load(['profile', 'department', 'personnelType']);
        }

        abort_unless(
            $viewer->can('leave.report.view') || $viewer->can('leave.management.manage'),
            403,
            'ไม่มีสิทธิ์ดูบัตรลาของบุคคลอื่น'
        );

        $target = User::with(['profile', 'department', 'personnelType'])->findOrFail($targetId);

        if ($viewer->hasRole('หัวหน้าแผนกวิชา / หัวหน้าฝ่าย')
            && !$viewer->hasAnyRole(['ผู้ดูแลระบบสูงสุด', 'Super Admin', 'ผู้อำนวยการ', 'Director', 'เจ้าหน้าที่บุคลากร (HR)'])) {
            $deptId = $viewer->department_id ?? ($viewer->profile?->department_id ?? null);
            abort_unless($deptId && $target->department_id === $deptId, 403, 'ดูได้เฉพาะบุคลากรในหน่วยงานของท่าน');
        }

        $this->accessLog->record($request, $target->id, 'leave_card', 'เปิดดูบัตรลาของบุคลากร');

        return $target;
    }

    /** รวบรวมข้อมูลบัตรลาของผู้ใช้ 1 คน ในปีงบประมาณเดียว */
    private function build(Request $request): array
    {
        $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'year'    => 'nullable|integer|min:2000|max:2100',
        ]);

        $user = $this->resolveTarget($request);
        $year = (int) $request->input('year', $this->policies->fiscalYearNumber($this->policies->fiscalYear(today()), today()));

        // ปีงบประมาณ: 1 ต.ค. ปีก่อนหน้า ถึง 30 ก.ย. ปีนั้น
        $periodStart = Carbon::create($year - 1, 10, 1)->startOfDay();
        $periodEnd = Carbon::create($year, 9, 30)->endOfDay();

        $leaves = Leave::with('type:id,name')
            ->where('user_id', $user->id)
            ->whereBetween('start_date', [$periodStart, $periodEnd])
            ->whereIn('status', ['Approved', 'Pending'])
            ->orderBy('start_date')
            ->get();

        $balances = LeaveBalance::with(['type:id,name', 'policy'])
            ->where('user_id', $user->id)
            ->where('year', $year)
            ->get();

        $approved = $leaves->where('status', 'Approved');

        $summary = $balances->map(function (LeaveBalance $balance) use ($approved) {
            $ofType = $approved->where('leave_type_id', $balance->leave_type_id);
            $unlimited = $balance->policy && $balance->policy->annual_quota === null;
            $entitlement = (float) $balance->quota + (float) $balance->carried_forward;

            return [
                'leave_type'     => $balance->type->name ?? '—',
                'carried_forward'=> (float) $balance->carried_forward,
                'quota'          => $unlimited ? null : $entitlement,
                'paid_quota'     => $balance->policy?->max_days_with_pay !== null ? (float) $balance->policy->max_days_with_pay : null,
                'times'          => $ofType->count(),
                'used_days'      => (float) $ofType->sum('total_days'),
                'paid_days'      => (float) $ofType->sum('paid_days'),
                'unpaid_days'    => (float) $ofType->sum('unpaid_days'),
                'pending_days'   => (float) $balance->reserved,
                'remaining'      => $unlimited ? null : $entitlement - (float) $balance->used - (float) $balance->reserved,
            ];
        })->values();

        return [
            'fiscal_year' => $year,
            'fiscal_year_th' => $year + 543,
            'period' => ['from' => $periodStart->toDateString(), 'to' => $periodEnd->toDateString()],
            // ผู้ใช้บางคนอาจยังไม่มี profile จึงต้องเข้าถึงแบบ null-safe ทุกจุด
            'user' => [
                'name'           => trim(($user->profile?->title ?? '') . ($user->profile?->first_name ?? '') . ' ' . ($user->profile?->last_name ?? '')) ?: $user->name,
                'position'       => $user->profile?->position ?? '—',
                'department'     => $user->department?->name ?? '—',
                'personnel_type' => $user->personnelType?->name ?? '—',
                'personnel_code' => $user->profile?->personnel_code ?? '—',
                'start_date'     => $user->profile?->start_date,
            ],
            'summary' => $summary,
            'totals' => [
                'times'       => $approved->count(),
                'used_days'   => (float) $approved->sum('total_days'),
                'paid_days'   => (float) $approved->sum('paid_days'),
                'unpaid_days' => (float) $approved->sum('unpaid_days'),
            ],
            'entries' => $leaves->map(fn (Leave $l) => [
                'document_number' => $l->document_number,
                'leave_type'      => $l->type->name ?? '—',
                'start_date'      => $l->start_date?->toDateString(),
                'end_date'        => $l->end_date?->toDateString(),
                'total_days'      => (float) $l->total_days,
                'paid_days'       => (float) $l->paid_days,
                'unpaid_days'     => (float) $l->unpaid_days,
                'status'          => $l->status,
            ])->values(),
        ];
    }

    public function show(Request $request)
    {
        return response()->json($this->build($request));
    }

    public function pdf(Request $request, ThaiDateService $thaiDateService)
    {
        $card = $this->build($request);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.leave_card', [
            'card' => $card,
            'thaiDateService' => $thaiDateService,
        ])->setPaper('a4', 'portrait');

        return $pdf->download("บัตรลา_{$card['user']['name']}_พ.ศ.{$card['fiscal_year_th']}.pdf");
    }
}
