<?php

namespace App\Http\Controllers;

use App\Models\FiscalYear;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeavePolicyService;
use App\Services\LeaveVisibility;
use Carbon\Carbon;
use Illuminate\Http\Request;

class LeaveQueryController extends Controller
{
    public function __construct(private LeavePolicyService $policies) {}

    public function index(Request $request, LeaveVisibility $visibility)
    {
        $user = $request->user();
        $canViewAll = $visibility->canSeeOthers($user);
        $perPage = min((int) ($request->input('per_page', 100)), 500);

        if ($canViewAll) {
            $query = Leave::with(['user:id,name,org_unit_id', 'user.orgUnit', 'type:id,name', 'approvals.approver:id,name', 'actionLogs.user:id,name'])
                ->orderBy('created_at', 'desc')
                /*
                 * ร่างเป็นของส่วนตัวจนกว่าจะส่ง — ยังไม่มีเลขที่หนังสือ ยังไม่เข้าสายอนุมัติ
                 * และเจ้าตัวยังแก้หรือลบได้ ผู้อื่นจึงไม่ควรเห็นแม้จะมีสิทธิ์ดูทั้งองค์กร
                 */
                ->where(fn ($scoped) => $scoped->where('status', '!=', 'Draft')->orWhere('user_id', $user->id));
            $query = $visibility->applyScope($query, $user);

            if ($request->fiscal_year) {
                $fy = FiscalYear::where('year', (int) $request->fiscal_year)->first();
                if ($fy) {
                    $query->whereBetween('start_date', [
                        Carbon::parse($fy->start_date)->startOfDay(),
                        Carbon::parse($fy->end_date)->endOfDay(),
                    ]);
                }
            }

            $paginated = $query->paginate($perPage);

            return response()->json([
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
            ]);
        }

        $leaves = Leave::with(['user:id,name,org_unit_id', 'user.orgUnit', 'type:id,name', 'approvals.approver:id,name', 'actionLogs.user:id,name'])
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    // ผู้ปฏิบัติงานแทนเห็นเฉพาะใบที่ยื่นจริงแล้ว ไม่เห็นร่างของคนอื่น
                    ->orWhere(fn ($assigned) => $assigned->where('substitute_id', $user->id)->where('status', '!=', 'Draft'));
            })
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json($leaves);
    }

    /**
     * โควตาวันลาคงเหลือของผู้ใช้ที่กำลังเข้าระบบ
     *
     * แถวใน `leave_balances` ถูกคีย์ด้วยปีที่ **นโยบายของการลาประเภทนั้น** กำหนด
     * (`LeavePolicyService::periodYear()`) นโยบายที่นับตามปีงบประมาณกับที่นับตามปีปฏิทิน
     * จึงได้เลขปีคนละตัวในช่วง 1 ต.ค. – 31 ธ.ค. ของทุกปี
     *
     * เดิมหน้าจอนี้หาแถวด้วย "เลขปีงบประมาณ" ตัวเดียวสำหรับทุกประเภทการลา ผลคือในช่วงนั้น
     * ประเภทที่นับตามปีปฏิทินจะอ่านคนละแถวกับที่ระบบจอง/ตัดจริง — ยื่นลาแล้วยอดไม่ลด
     * ยกเลิกแล้วยอดไม่คืน หรือหายไปจากหน้าจอทั้งแถว ทั้งที่ข้อมูลในฐานถูกต้อง
     */
    public function balances(Request $request)
    {
        $userId = $request->user()->id;
        $requestedYear = $request->input('fiscal_year');
        $selectedFY = $requestedYear
            ? FiscalYear::where('year', (int) $requestedYear)->first()
            : FiscalYear::current();

        /*
         * วันอ้างอิงของรอบที่ผู้ใช้กำลังดู — ใช้ "วันนี้" ถ้าวันนี้อยู่ในรอบนั้นจริง
         * มิฉะนั้นใช้วันเริ่มรอบ เพื่อให้ปีปฏิทินที่ได้เป็นปีที่รอบนั้นเริ่มต้น
         */
        $reference = today();
        if ($selectedFY) {
            $fyStart = Carbon::parse($selectedFY->start_date)->startOfDay();
            $fyEnd = Carbon::parse($selectedFY->end_date)->endOfDay();
            $reference = $reference->between($fyStart, $fyEnd) ? $reference : $fyStart->copy();
        }

        $fiscalYearNumber = $this->policies->fiscalYearNumber($selectedFY, $reference);
        $calendarYear = (int) $reference->year;

        $balances = LeaveBalance::with(['type', 'policy'])
            ->where('user_id', $userId)
            ->whereIn('year', array_unique([$fiscalYearNumber, $calendarYear]))
            ->get()
            ->each(fn (LeaveBalance $balance) => $this->attachPolicy($request->user(), $balance, $reference))
            // เก็บเฉพาะแถวที่ตรงกับเกณฑ์นับรอบของนโยบายตัวเอง
            ->filter(fn (LeaveBalance $balance) => (int) $balance->year === $this->periodYearFor($balance, $fiscalYearNumber, $calendarYear))
            ->values();

        foreach ($balances as $balance) {
            [$windowStart, $windowEnd] = $this->periodWindowFor($balance, $selectedFY, $reference);

            $balance->pending = (float) $balance->reserved;
            $balance->leaves_pending_days = $this->daysInWindow($userId, $balance->leave_type_id, 'Pending', $windowStart, $windowEnd);
            $balance->leaves_used_days = $this->daysInWindow($userId, $balance->leave_type_id, 'Approved', $windowStart, $windowEnd);
            $balance->unlimited = $balance->relationLoaded('policy')
                && $balance->policy !== null
                && $balance->policy->annual_quota === null;

            $paidCap = $balance->policy?->max_days_with_pay;
            $balance->paid_quota = $paidCap === null ? null : (float) $paidCap;
            $balance->paid_remaining = $paidCap === null
                ? null
                : max(0.0, (float) $paidCap - (float) $balance->used - (float) $balance->reserved);
        }

        return response()->json($balances);
    }

    /**
     * ผูกนโยบายที่ใช้จริงเข้ากับแถวโควตาที่ยังไม่มี `leave_policy_id`
     *
     * แถวที่ถูกสร้างตอนติดตั้งหรือย้ายข้อมูลมีแต่ตัวเลขโควตา ไม่ได้ผูกนโยบายไว้
     * ผลคือหน้าจอตัดสินว่า "ไม่จำกัดโควตา" ไม่ได้ ประเภทการลาที่ระเบียบไม่จำกัดวัน
     * (ลาเข้ารับการตรวจเลือก ลาไปศึกษาต่อ ลาติดตามคู่สมรส ฯลฯ) จึงแสดงเป็น
     * "เหลือ 0 วัน" เหมือนไม่มีสิทธิ์ลาเลย และเพดานวันที่ได้รับเงินเดือนก็หายไปด้วย
     */
    private function attachPolicy(User $user, LeaveBalance $balance, Carbon $reference): void
    {
        if ($balance->relationLoaded('policy') && $balance->policy !== null) {
            return;
        }

        try {
            $balance->setRelation('policy', $this->policies->resolve($user, (int) $balance->leave_type_id, $reference));
        } catch (\Throwable) {
            // ไม่มีนโยบายให้ผูก — แสดงตามตัวเลขในแถวเหมือนเดิม ดีกว่าทำให้ทั้งหน้าพัง
            $balance->setRelation('policy', null);
        }
    }

    /** เลขปีที่แถวโควตาของนโยบายนี้ต้องใช้ — ตรงกับที่ `LeaveService` ใช้ตอนจองและตัดวัน */
    private function periodYearFor(LeaveBalance $balance, int $fiscalYearNumber, int $calendarYear): int
    {
        return $balance->policy?->period_basis === 'calendar_year' ? $calendarYear : $fiscalYearNumber;
    }

    /**
     * ช่วงวันที่ของรอบโควตาหนึ่งแถว — ใช้รวมยอดใบลาที่ยื่นในรอบเดียวกันเท่านั้น
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodWindowFor(LeaveBalance $balance, ?FiscalYear $selectedFY, Carbon $reference): array
    {
        if ($balance->policy?->period_basis === 'calendar_year') {
            return [$reference->copy()->startOfYear(), $reference->copy()->endOfYear()];
        }

        if ($selectedFY) {
            return [
                Carbon::parse($selectedFY->start_date)->startOfDay(),
                Carbon::parse($selectedFY->end_date)->endOfDay(),
            ];
        }

        // ไม่มีรอบปีงบประมาณในฐานข้อมูล — ใช้เกณฑ์เดียวกับ `fiscalYearNumber()` คือเริ่ม 1 ต.ค.
        $endYear = $reference->month >= 10 ? $reference->year + 1 : $reference->year;

        return [
            Carbon::create($endYear - 1, 10, 1)->startOfDay(),
            Carbon::create($endYear, 9, 30)->endOfDay(),
        ];
    }

    private function daysInWindow(int $userId, int $leaveTypeId, string $status, Carbon $from, Carbon $to): float
    {
        return (float) Leave::where('user_id', $userId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('status', $status)
            ->whereBetween('start_date', [$from, $to])
            ->sum('total_days');
    }

    public function teamCalendar(Request $request)
    {
        $user = $request->user();
        $query = Leave::with(['user.profile', 'user.orgUnit', 'type'])
            ->whereIn('status', ['Approved', 'Pending']);

        if (! $user->can('attendance.view_all')) {
            $orgUnitId = $user->org_unit_id ?? ($user->profile->org_unit_id ?? null);
            if ($orgUnitId) {
                $query->whereHas('user', function ($q) use ($orgUnitId) {
                    $q->where('org_unit_id', $orgUnitId);
                });
            } else {
                $query->where('user_id', $user->id);
            }
        }

        $year = date('Y');
        $query->where(function ($q) use ($year) {
            $q->whereYear('start_date', $year)
                ->orWhereYear('end_date', $year);
        });

        return response()->json($query->get());
    }

    public function types()
    {
        return response()->json(LeaveType::all());
    }
}
