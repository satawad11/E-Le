<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;

class LeaveBalanceService
{
    /**
     * Calculate prorated leave quota for a user.
     * Returns the allowed quota for the specified year.
     */
    public function calculateQuota(User $user, LeaveType $leaveType, FiscalYear $fiscalYear): float
    {
        // If not prorated, just return max_days
        if (! $leaveType->is_prorated) {
            return (float) $leaveType->max_days;
        }

        $startDate = null;
        if ($user->profile && $user->profile->start_date) {
            $startDate = Carbon::parse($user->profile->start_date);
        }

        if (! $startDate) {
            return (float) $leaveType->max_days;
        }

        $fyStart = Carbon::parse($fiscalYear->start_date);
        $fyEnd = Carbon::parse($fiscalYear->end_date);

        // If user started before this fiscal year, no proration needed for this year
        if ($startDate->lt($fyStart)) {
            return (float) $leaveType->max_days;
        }

        // If user started after this fiscal year, 0 quota
        if ($startDate->gt($fyEnd)) {
            return 0;
        }

        // Calculate months worked in this fiscal year
        // We can use a simple proportion: (days_worked_in_fy / total_days_in_fy) * max_days
        // Or months_worked / 12 * max_days
        $daysInYear = $fyStart->diffInDays($fyEnd) + 1;
        $daysWorked = $startDate->diffInDays($fyEnd) + 1;

        $prorated = ($daysWorked / $daysInYear) * $leaveType->max_days;

        // Round to nearest 0.5 (half day)
        return floor($prorated * 2) / 2;
    }
}
