<?php

namespace App\Console\Commands;

use App\Models\Saraban\NumberSeries;
use App\Models\User;
use App\Services\Saraban\NumberingService;
use Illuminate\Console\Command;
use Throwable;

/**
 * INT-20 — process ลูกของ `saraban:concurrency-check` (ปิด RISK-22)
 *
 * **ซ่อนจากรายการคำสั่ง** เพราะไม่มีเหตุผลให้ใครเรียกเอง — มันมีความหมาย
 * เฉพาะเมื่อถูกเปิดพร้อมกันหลายตัวโดยคำสั่งแม่
 */
class NumberingConcurrencyWorkerCommand extends Command
{
    protected $hidden = true;

    protected $signature = 'saraban:concurrency-worker {series} {count}';

    protected $description = 'ขอเลขหนังสือซ้ำ ๆ เพื่อใช้ทดสอบการแย่งกัน';

    public function handle(NumberingService $numbering): int
    {
        $series = NumberSeries::query()->find((int) $this->argument('series'));
        $actor = User::query()->first();

        if ($series === null || $actor === null) {
            return self::FAILURE;
        }

        for ($i = 0; $i < (int) $this->argument('count'); $i++) {
            try {
                $numbering->reserve($series, $actor);
            } catch (Throwable $exception) {
                /*
                 * เขียนออก stderr แล้วทำต่อ — คำสั่งแม่ตัดสินผลจาก **จำนวนเลข
                 * ที่ออกมาจริง** ไม่ใช่จาก exit code ของลูก · ถ้าลูกล้มเงียบ ๆ
                 * จำนวนที่ขาดไปจะทำให้เกณฑ์ "ได้ครบ" ไม่ผ่านเอง
                 */
                fwrite(STDERR, $exception->getMessage()."\n");
            }
        }

        return self::SUCCESS;
    }
}
