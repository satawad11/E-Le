<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

/**
 * INT-15 — ล้างกุญแจกันทำซ้ำที่หมดอายุ (SoT §17)
 *
 * ตารางนี้โตตามจำนวนคำขอที่ส่งกุญแจมา ถ้าไม่ล้างจะกลายเป็นตารางที่ใหญ่ที่สุด
 * ในระบบภายในไม่กี่เดือน · ลบเฉพาะแถวที่หมดอายุแล้ว ซึ่งไม่มีทางถูกใช้ซ้ำได้อีก
 */
class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'ลบกุญแจกันทำซ้ำที่หมดอายุแล้ว';

    public function handle(): int
    {
        $deleted = IdempotencyKey::query()->where('expires_at', '<', now())->delete();

        $this->info("ลบกุญแจที่หมดอายุ {$deleted} รายการ");

        return self::SUCCESS;
    }
}
