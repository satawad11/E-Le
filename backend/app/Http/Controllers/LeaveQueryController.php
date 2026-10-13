<?php

namespace App\Http\Controllers;

use App\Models\FiscalYear;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
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
            $query = Leave::with(['user:id,name,department_id', 'user.department', 'type:id,name', 'approvals.approver:id,name', 'actionLogs.user:id,name'])
                ->orderBy('created_at', 'desc');
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

        $leaves = Leave::with(['user:id,name,department_id', 'user.department', 'type:id,name', 'approvals.approver:id,name', 'actionLogs.user:id,name'])
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere('substitute_id', $user->id);
            })
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json($leaves);
    }

    public function balances(Request $request)
    {
        $userId = $request->user()->id;
        $requestedYear = $request->input('fiscal_year');
        $selectedFY = $requestedYear
            ? FiscalYear::where('year', (int) $requestedYear)->first()
            : FiscalYear::current();

        $year = $this->policies->fiscalYearNumber(
            $selectedFY,
            $selectedFY ? Carbon::parse($selectedFY->start_date) : today()
        );

        // รอบปีปัจจุบัน/อนาคต: สร้างยอดตั้งต้นตามนโยบายให้ครบก่อน (ปีที่ผ่านไปแล้วไม่สร้างย้อนหลัง)
        $anchor = $selectedFY ? Carbon::parse($selectedFY->start_date) : today();
        if (! $selectedFY || Carbon::parse($selectedFY->end_date)->gte(today())) {
            $user = $request->user()->loadMissing(['personnel', 'currentAssignment', 'profile']);
            $this->policies->ensureBalances($user, $anchor->lt(today()) ? today() : $anchor);
        }

        $balances = LeaveBalance::with(['type', 'policy'])
            ->where('user_id', $userId)
            ->where('year', $year)
            ->get();

        $pendingQuery = Leave::selectRaw('leave_type_id, SUM(total_days) as pending_days')
            ->where('user_id', $userId)
            ->where('status', 'Pending');
        $approvedQuery = Leave::selectRaw('leave_type_id, SUM(total_days) as used_days')
            ->where('user_id', $userId)
            ->where('status', 'Approved');

        if ($selectedFY) {
            $startDate = Carbon::parse($selectedFY->start_date)->startOfDay();
            $endDate = Carbon::parse($selectedFY->end_date)->endOfDay();
            $pendingQuery->whereBetween('start_date', [$startDate, $endDate]);
            $approvedQuery->whereBetween('start_date', [$startDate, $endDate]);
        } else {
            $pendingQuery->whereYear('start_date', $year);
            $approvedQuery->whereYear('start_date', $year);
        }

        $pendingLeaves = $pendingQuery->groupBy('leave_type_id')->pluck('pending_days', 'leave_type_id')->toArray();
        $approvedLeaves = $approvedQuery->groupBy('leave_type_id')->pluck('used_days', 'leave_type_id')->toArray();

        foreach ($balances as $balance) {
            $balance->pending = (float) $balance->reserved;
            $balance->leaves_pending_days = (float) ($pendingLeaves[$balance->leave_type_id] ?? 0);
            $balance->leaves_used_days = (float) ($approvedLeaves[$balance->leave_type_id] ?? 0);
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

    public function teamCalendar(Request $request)
    {
        $user = $request->user();
        $query = Leave::with(['user.profile', 'user.department', 'type'])
            ->whereIn('status', ['Approved', 'Pending']);

        if (! $user->can('attendance.view_all')) {
            $departmentId = $user->department_id ?? ($user->profile->department_id ?? null);
            if ($departmentId) {
                $query->whereHas('user', function ($q) use ($departmentId) {
                    $q->where('department_id', $departmentId);
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
