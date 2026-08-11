<?php

namespace App\Console\Commands;

use App\Models\LeavePolicy;
use App\Services\LeavePolicyService;
use Illuminate\Console\Command;

/**
 * ดันโควตาจาก leave_policies ลง leave_balances ที่สร้างไว้แล้ว
 * (แถวเดิมถูกสร้างด้วย firstOrCreate จึงไม่อัปเดตตามนโยบายเอง)
 */
class SyncLeaveQuotas extends Command
{
    protected $signature = 'leave:sync-quotas';
    protected $description = 'อัปเดตโควตาใน leave_balances ให้ตรงกับนโยบายสิทธิการลาที่เปิดใช้งานอยู่';

    public function handle(LeavePolicyService $policies): int
    {
        $totalSynced = 0;
        $totalOver = 0;
        $rows = [];

        LeavePolicy::with(['personnelType', 'leaveType'])
            ->where('is_active', true)
            ->orderBy('id')
            ->each(function (LeavePolicy $policy) use ($policies, &$totalSynced, &$totalOver, &$rows) {
                $result = $policies->syncBalances($policy);
                if ($result['synced'] === 0) return;

                $rows[] = [
                    $policy->personnelType?->name ?? $policy->personnel_type_id,
                    $policy->leaveType?->name ?? $policy->leave_type_id,
                    $policy->annual_quota ?? 'ไม่จำกัด',
                    $result['synced'],
                    $result['over_quota'] ?: '-',
                ];
                $totalSynced += $result['synced'];
                $totalOver += $result['over_quota'];
            });

        if ($rows) {
            $this->table(['ประเภทบุคลากร', 'ประเภทลา', 'โควตา', 'อัปเดต (แถว)', 'เกินโควตา'], $rows);
        }

        $this->info("อัปเดตโควตาแล้ว {$totalSynced} แถว");
        if ($totalOver > 0) {
            $this->warn("มี {$totalOver} แถวที่ใช้วันลาไปเกินโควตาใหม่แล้ว กรุณาตรวจสอบกับฝ่ายบุคคล");
        }

        return self::SUCCESS;
    }
}
