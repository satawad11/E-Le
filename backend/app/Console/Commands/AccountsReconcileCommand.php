<?php

namespace App\Console\Commands;

use App\Services\Access\AccessReviewService;
use App\Services\Access\AccountLifecycleService;
use Illuminate\Console\Command;

/**
 * Job รายวัน (CORE-03): ทำ JML ซ้ำทุกข้อให้ตรงกับสถานะบุคลากร · ปลดล็อกที่ครบเวลา · ระงับบัญชีไม่เข้าใช้ 90 วัน
 * · ปิด grant/override ที่หมดอายุ · ปิดรอบทบทวนสิทธิ์ที่เลยกำหนด (ถอนรายการที่ไม่ได้ยืนยัน)
 *
 * idempotent — รันซ้ำได้ผลเหมือนเดิม
 */
class AccountsReconcileCommand extends Command
{
    protected $signature = 'accounts:reconcile';

    protected $description = 'ปรับบัญชีผู้ใช้ให้ตรงกับข้อมูลบุคลากรและนโยบายความปลอดภัย (JML รายวัน)';

    public function handle(AccountLifecycleService $lifecycle, AccessReviewService $reviews): int
    {
        $tally = $lifecycle->reconcile();
        $tally['reviews_closed'] = $reviews->closeDue();

        foreach ($tally as $key => $count) {
            $this->line(sprintf('%-28s %d', $key, $count));
        }

        return self::SUCCESS;
    }
}
