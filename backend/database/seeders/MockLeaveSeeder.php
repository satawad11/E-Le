<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\LeaveApproval;
use Carbon\Carbon;
use Illuminate\Support\Str;

class MockLeaveSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::where('is_active', true)->get();

        $managers = User::whereHas('role', function ($q) {
            $q->whereIn('name', ['Director', 'Deputy Director']);
        })->get();

        $hrUser = User::whereHas('role', function ($q) {
            $q->where('name', 'HR Head');
        })->first();

        $types = LeaveType::all();

        if ($users->isEmpty() || $types->isEmpty() || $managers->isEmpty()) {
            $this->command->warn('Missing Users or LeaveTypes or Managers. Please run default seeders first.');
            return;
        }

        $this->command->info('Generating mock leaves...');

        $statuses = ['Pending', 'Approved', 'Rejected', 'Cancelled'];
        $cancellationStatuses = ['None', 'Pending', 'Approved', 'Rejected'];

        foreach ($users as $user) {
            foreach ($types as $type) {
                // Generate 1-3 leaves per type for each user
                $leaveCount = rand(1, 3);
                
                for ($i = 0; $i < $leaveCount; $i++) {
                    $start = Carbon::now()->subDays(rand(1, 100));
                    $days = rand(1, 3);
                    $end = clone $start;
                    $end->addDays($days - 1);
                    
                    $status = $statuses[array_rand($statuses)];
                    $cancellationStatus = 'None';
                    if ($status === 'Approved' && rand(1, 10) > 8) {
                        $cancellationStatus = 'Pending';
                    } else if ($status === 'Cancelled') {
                        $cancellationStatus = 'Approved';
                        $status = 'Approved'; // To simulate it was approved then cancelled
                    }

                    // Assign a random substitute for 30% of leaves
                    $substitute_id = null;
                    $substitute_status = null;
                    if (rand(1, 10) > 7) {
                        $potentialSubstitutes = $users->where('id', '!=', $user->id);
                        if ($potentialSubstitutes->isNotEmpty()) {
                            $substitute = $potentialSubstitutes->random();
                            $substitute_id = $substitute->id;
                            $substitute_status = ['Pending', 'Accepted', 'Rejected'][rand(0, 2)];
                        }
                    }

                    $leave = Leave::create([
                        'user_id' => $user->id,
                        'leave_type_id' => $type->id,
                        'document_number' => 'L-' . date('Ym') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
                        'start_date' => $start,
                        'end_date' => $end,
                        'total_days' => $days,
                        'reason' => 'ทดสอบการลา ' . $type->name . ' (Mock Data)',
                        'contact_address' => 'บ้านพัก',
                        'status' => $status === 'Cancelled' ? 'Cancelled' : $status,
                        'approval_level' => ($status === 'Approved' || $status === 'Rejected') ? 1 : 0,
                        'substitute_id' => $substitute_id,
                        'substitute_status' => $substitute_status,
                        'substitute_reason' => $substitute_id ? 'ฝากงานโปรเจค X' : null,
                        'cancellation_status' => $cancellationStatus,
                        'cancellation_reason' => $cancellationStatus !== 'None' ? 'ต้องการเลื่อนวันลา' : null,
                        'hr_verified_by' => ($status !== 'Pending' && $hrUser && rand(0,1)) ? $hrUser->id : null,
                        'hr_verified_at' => ($status !== 'Pending' && $hrUser && rand(0,1)) ? now() : null,
                    ]);

                    // Generate approvals if not pending
                    if ($status === 'Approved' || $status === 'Rejected') {
                        LeaveApproval::create([
                            'leave_id' => $leave->id,
                            'approver_id' => $managers->random()->id,
                            'status' => $status,
                            'comment' => $status === 'Approved' ? 'อนุมัติ (Mock)' : 'ไม่อนุมัติ (Mock)',
                            'level' => 1,
                        ]);
                    }
                }
            }
        }

        $this->command->info('Mock leaves generated successfully!');
    }
}
