<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Leave;
use App\Models\User;
use App\Models\FiscalYear;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Return dashboard statistics for the current user.
     * Employees see their own stats; Managers see department-wide stats.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $roleName = $user->role->name;

        $baseQuery = Leave::query();

        // Get current fiscal year from fiscal_years table
        $currentFY = FiscalYear::current();

        // Determine which fiscal year to display
        $requestedYear = $request->input('fiscal_year');
        $viewSelf = filter_var($request->input('view_self', false), FILTER_VALIDATE_BOOLEAN);

        if ($requestedYear) {
            // User selected a specific fiscal year from dropdown
            $selectedFY = FiscalYear::where('year', (int) $requestedYear)->first();
        } else {
            $selectedFY = $currentFY;
        }

        if ($selectedFY) {
            // Use exact start/end dates from the fiscal year record
            $baseQuery->whereBetween('start_date', [
                Carbon::parse($selectedFY->start_date)->startOfDay(),
                Carbon::parse($selectedFY->end_date)->endOfDay(),
            ]);
            $fiscalYearLabel = $selectedFY->year;
        } else {
            // Fallback: use calendar year
            $fallbackYear = $requestedYear ? (int)$requestedYear - 543 : date('Y');
            $baseQuery->whereYear('start_date', $fallbackYear);
            $fiscalYearLabel = $fallbackYear + 543;
        }

        if ($viewSelf) {
            $baseQuery->where('user_id', $user->id);
        } else {
            if (!in_array($roleName, ['Super Admin', 'Director', 'HR Head', 'Deputy Director'])) {
                $baseQuery->where('user_id', $user->id);
            }
        }

        // Count by status
        $statusCounts = (clone $baseQuery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $pendingCount   = $statusCounts['Pending'] ?? 0;
        $approvedCount  = $statusCounts['Approved'] ?? 0;
        $rejectedCount  = $statusCounts['Rejected'] ?? 0;
        $totalCount     = array_sum($statusCounts);

        // Monthly breakdown (approved leaves)
        $year = $selectedFY ? Carbon::parse($selectedFY->start_date)->year : ($requestedYear ? (int)$requestedYear - 543 : date('Y'));

        $monthlyCounts = (clone $baseQuery)
            ->where('status', 'Approved')
            ->selectRaw('MONTH(start_date) as month, count(*) as count')
            ->groupByRaw('MONTH(start_date)')
            ->pluck('count', 'month')
            ->toArray();

        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthly[] = [
                'month' => Carbon::create($year, $m)->translatedFormat('M'),
                'count' => $monthlyCounts[$m] ?? 0,
            ];
        }

        // Count by Leave Type
        $byTypeCounts = (clone $baseQuery)
            ->where('status', 'Approved')
            ->selectRaw('leave_type_id, count(*) as count, sum(total_days) as days')
            ->groupBy('leave_type_id')
            ->with('type')
            ->get()
            ->map(function ($item) {
                return [
                    'type_name' => $item->type->name ?? '—',
                    'count'     => $item->count,
                    'days'      => $item->days,
                ];
            });

        // Get all fiscal years for the dropdown
        $allFiscalYears = FiscalYear::latestYear()->get(['id', 'year', 'name', 'is_current']);

        return response()->json([
            'pending'      => $pendingCount,
            'approved'     => $approvedCount,
            'rejected'     => $rejectedCount,
            'total'        => $totalCount,
            'monthly'      => $monthly,
            'by_type'      => $byTypeCounts,
            'fiscal_year'  => $fiscalYearLabel,
            'fiscal_years' => $allFiscalYears,
        ]);
    }
}
