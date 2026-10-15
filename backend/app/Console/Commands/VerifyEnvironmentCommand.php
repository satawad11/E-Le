<?php

namespace App\Console\Commands;

use App\Support\ProductionEnvironmentGuard;
use Illuminate\Console\Command;

/**
 * SEC-022 — ด่านตรวจ environment ที่ container ต้องผ่านก่อนเริ่มทำงาน
 *
 * เรียกก่อน `php artisan migrate` ใน docker-compose.yml ทุก service
 * (backend / worker / scheduler) เมื่อคืนค่าไม่ใช่ 0 คำสั่งต่อ ๆ ไปจะไม่ถูกรัน
 * และ container ออกทันที — ระบบที่ตั้งค่าไม่ปลอดภัยจึงไม่มีทางขึ้นให้บริการ
 */
class VerifyEnvironmentCommand extends Command
{
    protected $signature = 'app:verify-environment';

    protected $description = 'ตรวจว่าค่า APP_ENV/APP_DEBUG และค่าที่เกี่ยวข้องปลอดภัยพอจะเริ่มระบบ';

    public function handle(): int
    {
        $problems = ProductionEnvironmentGuard::problemsFromConfig();

        if ($problems !== []) {
            $this->error('การตั้งค่า environment ไม่ปลอดภัย ระบบจึงไม่เริ่มทำงาน:');

            foreach ($problems as $problem) {
                $this->line('  - '.$problem);
            }

            return self::FAILURE;
        }

        $this->info(sprintf(
            'การตั้งค่า environment ผ่านการตรวจ (APP_ENV=%s, APP_DEBUG=%s)',
            (string) config('app.env'),
            config('app.debug') ? 'true' : 'false',
        ));

        return self::SUCCESS;
    }
}
