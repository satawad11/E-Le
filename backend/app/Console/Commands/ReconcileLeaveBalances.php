<?php

namespace App\Console\Commands;

use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Services\LeavePolicyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * คำนวณ used/reserved ในตาราง leave_balances ใหม่จากใบลาจริง
 * ใช้ซ่อมข้อมูลที่ค้าง เช่น ใบลาที่ถูกยกเลิก/ลบตอนสถานะ Pending แล้วไม่ได้คืนวันที่จองไว้
 */
class ReconcileLeaveBalances extends Command
{
    protected $signature = 'leave:reconcile-balances {--dry-run : แสดงผลที่จะแก้โดยไม่บันทึก} {--user= : จำกัดเฉพาะ user id}';

    protected $description = 'คำนวณวันลาที่ใช้ไป/ที่จองไว้ใหม่จากใบลาจริง แล้วแก้ยอดใน leave_balances ให้ตรง';

    public function handle(LeavePolicyService $policies): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // ยอดที่ "ควรจะเป็น" คิดจากใบลาที่ยังไม่ถูกลบเท่านั้น
        $expected = [];
        Leave::with('policy')
            ->whereIn('status', ['Pending', 'Approved'])
            ->when($this->option('user'), fn ($q, $id) => $q->where('user_id', $id))
            ->chunkById(500, function ($leaves) use (&$expected, $policies) {
                foreach ($leaves as $leave) {
                    $key = $leave->user_id.'|'.$leave->leave_type_id.'|'.$policies->periodYearForLeave($leave);
                    $expected[$key] ??= ['used' => 0.0, 'reserved' => 0.0];
                    if ($leave->status === 'Approved') {
                        $expected[$key]['used'] += (float) $leave->total_days;
                    } else {
                        $expected[$key]['reserved'] += (float) $leave->reserved_days;
                    }
                }
            });

        $fixed = 0;
        $rows = [];

        LeaveBalance::with('type')
            ->when($this->option('user'), fn ($q, $id) => $q->where('user_id', $id))
            ->chunkById(500, function ($balances) use ($expected, $dryRun, &$fixed, &$rows) {
                foreach ($balances as $balance) {
                    $key = $balance->user_id.'|'.$balance->leave_type_id.'|'.$balance->year;
                    $want = $expected[$key] ?? ['used' => 0.0, 'reserved' => 0.0];

                    if (abs((float) $balance->used - $want['used']) < 0.001
                        && abs((float) $balance->reserved - $want['reserved']) < 0.001) {
                        continue;
                    }

                    $rows[] = [
                        $balance->user_id,
                        $balance->type->name ?? $balance->leave_type_id,
                        $balance->year,
                        (float) $balance->used.' → '.$want['used'],
                        (float) $balance->reserved.' → '.$want['reserved'],
                    ];
                    $fixed++;

                    if (! $dryRun) {
                        DB::transaction(function () use ($balance, $want) {
                            $locked = LeaveBalance::whereKey($balance->id)->lockForUpdate()->first();
                            $locked->used = $want['used'];
                            $locked->reserved = $want['reserved'];
                            $locked->save();
                        });
                    }
                }
            });

        if ($rows) {
            $this->table(['user_id', 'ประเภทลา', 'ปี', 'ใช้ไป', 'จองไว้'], $rows);
        }

        $this->info($dryRun
            ? "พบยอดที่ไม่ตรง {$fixed} รายการ (โหมด dry-run ยังไม่บันทึก)"
            : "ปรับยอดให้ตรงแล้ว {$fixed} รายการ");

        return self::SUCCESS;
    }
}
