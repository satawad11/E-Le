<?php

namespace App\Console\Commands;

use App\Services\RoleGrantService;
use Illuminate\Console\Command;

/**
 * ถอนบทบาทที่ถึงวันหมดอายุกลับเป็นบทบาทพื้นฐาน (ACCESS-GOV-01 · C3) — ตั้งเวลาให้รันทุกวันใน routes/console.php
 */
class ExpireRoleGrants extends Command
{
    protected $signature = 'access:expire-role-grants';

    protected $description = 'ถอนบทบาทที่หมดอายุตามทะเบียนการมอบบทบาท';

    public function handle(RoleGrantService $grants): int
    {
        $count = $grants->expireDue();
        $this->info("ถอนบทบาทที่หมดอายุแล้ว {$count} รายการ");

        return self::SUCCESS;
    }
}
