<?php

namespace App\Console\Commands;

use App\Models\Saraban\Document;
use App\Models\Saraban\RetentionSchedule;
use App\Models\User;
use App\Services\Saraban\RetentionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * process ลูกของ `saraban:concurrency-check --disposal`
 *
 * **ซ่อนจากรายการคำสั่ง** เพราะไม่มีเหตุผลให้ใครเรียกเอง — มันมีความหมายเฉพาะ
 * เมื่อถูกเปิดพร้อมกันสองตัวบนหนังสือฉบับเดียวกันโดยคำสั่งแม่
 *
 * ล้มเหลวได้เป็นเรื่องปกติ (อีกฝ่ายชนะการแย่งกัน) — คำสั่งแม่ตัดสินจาก
 * **สถานะสุดท้ายในฐานข้อมูล** ไม่ใช่จาก exit code ของลูก
 */
class DisposalRaceWorkerCommand extends Command
{
    protected $hidden = true;

    protected $signature = 'saraban:disposal-race-worker {document} {mode : hold|dispose}';

    protected $description = 'อายัดหรือทำลายหนังสือหนึ่งฉบับ เพื่อใช้ทดสอบการแย่งกัน';

    public function handle(RetentionService $retention): int
    {
        $document = Document::query()->find((int) $this->argument('document'));
        $actor = User::query()->first();

        if ($document === null || $actor === null) {
            return self::FAILURE;
        }

        try {
            if ($this->argument('mode') === 'hold') {
                $retention->placeHold($document, 'RACE-'.$document->id, 'ทดสอบการแย่งกัน', $actor);

                return self::SUCCESS;
            }

            $schedule = RetentionSchedule::query()->where('document_id', $document->id)->firstOrFail();
            $retention->dispose($schedule, $actor, 'ทดสอบการแย่งกัน');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage()."\n");

            return self::FAILURE;
        }
    }
}
