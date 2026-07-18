<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\FiscalYear;
use Carbon\Carbon;

class CarryForwardLeaveCommand extends Command
{
    protected $signature = 'leave:carry-forward {--year= : The fiscal year to process (e.g. 2026)}';
    protected $description = 'Carry forward unused vacation leaves to the next fiscal year based on years of service';

    public function handle()
    {
        $year = $this->option('year') ?? date('Y');
        $nextYear = $year + 1;

        $vacationType = LeaveType::where('name', 'like', '%ลาพักผ่อน%')->first();
        
        if (!$vacationType) {
            $this->error('Vacation leave type not found.');
            return;
        }

        $users = User::with('profile')->where('is_active', true)->get();

        foreach ($users as $user) {
            $currentBalance = LeaveBalance::where('user_id', $user->id)
                ->where('leave_type_id', $vacationType->id)
                ->where('year', $year)
                ->first();

            $remaining = $currentBalance ? ($currentBalance->quota + $currentBalance->carry_forward_days) - $currentBalance->used : 0;
            $remaining = max(0, $remaining);

            // Calculate years of service
            $yearsOfService = 0;
            if ($user->profile && $user->profile->start_work_date) {
                $yearsOfService = Carbon::parse($user->profile->start_work_date)->diffInYears(now());
            }

            // Govt Rule: max accumulate 20 days. If > 10 years service, max 30 days.
            $maxAccumulate = $yearsOfService >= 10 ? 30 : 20;

            $carryForward = min($remaining, $maxAccumulate);

            // Create or update balance for next year
            LeaveBalance::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'leave_type_id' => $vacationType->id,
                    'year' => $nextYear,
                ],
                [
                    'quota' => 10, // Default yearly quota
                    'used' => 0,
                    'carry_forward_days' => $carryForward
                ]
            );
        }

        $this->info("Successfully carried forward leave balances from $year to $nextYear.");
    }
}
