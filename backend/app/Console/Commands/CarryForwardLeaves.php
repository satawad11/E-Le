<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use App\Models\FiscalYear;

class CarryForwardLeaves extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:carry-forward-leaves';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Carry forward unused leaves to the next year based on leave type settings';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting leave carry-forward process...');

        $currentFiscalYear = FiscalYear::where('is_current', true)->first();
        if (!$currentFiscalYear) {
            $this->error('No current fiscal year found.');
            return;
        }

        $nextFiscalYear = FiscalYear::where('year', $currentFiscalYear->year + 1)->first();
        if (!$nextFiscalYear) {
            $this->error('Next fiscal year not found. Please create it first.');
            return;
        }

        $leaveTypes = LeaveType::where('is_carry_forward', true)->get();
        if ($leaveTypes->isEmpty()) {
            $this->info('No leave types configured for carry-forward.');
            return;
        }

        $users = User::where('status', 'Active')->get();
        $count = 0;

        foreach ($users as $user) {
            foreach ($leaveTypes as $leaveType) {
                $currentBalance = LeaveBalance::where('user_id', $user->id)
                    ->where('leave_type_id', $leaveType->id)
                    ->where('year', $currentFiscalYear->year)
                    ->first();

                if ($currentBalance) {
                    $remaining = ($currentBalance->quota + $currentBalance->carried_forward) - $currentBalance->used;

                    if ($remaining > 0) {
                        $carryForwardAmount = $remaining;
                        
                        // Limit by max carry forward if configured
                        if ($leaveType->max_carry_forward !== null && $carryForwardAmount > $leaveType->max_carry_forward) {
                            $carryForwardAmount = $leaveType->max_carry_forward;
                        }

                        LeaveBalance::updateOrCreate(
                            [
                                'user_id' => $user->id,
                                'leave_type_id' => $leaveType->id,
                                'year' => $nextFiscalYear->year,
                            ],
                            [
                                'carried_forward' => $carryForwardAmount,
                            ]
                        );
                        $count++;
                    }
                }
            }
        }

        $this->info("Successfully processed carry-forward for {$count} records into year {$nextFiscalYear->year}.");
    }
}
