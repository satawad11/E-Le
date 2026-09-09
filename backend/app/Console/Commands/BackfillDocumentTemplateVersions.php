<?php

namespace App\Console\Commands;

use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * LS-08 — สร้างเวอร์ชันแรกให้แม่แบบที่ยังไม่มีเวอร์ชันเลย
 *
 * แม่แบบที่ถูกสร้างก่อนมีระบบเวอร์ชัน (`DocumentTemplate::booted()`) ไม่มีแถวใน
 * `document_template_versions` เลย แม่แบบเหล่านั้นจึงถูกจัดการจากหน้าออกแบบไม่ได้
 * และ resolver หาเวอร์ชันที่มีผลไม่เจอ
 *
 * กติกาที่ยึด — เพราะเป็นการเติมข้อมูลที่เกี่ยวกับเอกสารราชการ:
 *
 *   1. **ไม่ย้อนหลัง** `effective_from` เป็นวันที่รันเสมอ ไม่มีการอ้างว่าแบบฟอร์มนี้
 *      มีผลมาตั้งแต่อดีต ซึ่งจะเป็นการเดาประวัติที่ตรวจสอบไม่ได้
 *   2. **ไม่แตะใบลา** ไม่มีการเขียน `leaves.document_template_version_id` ให้ใบใด
 *      ใบเก่าที่ยังไม่ได้ pin จึงพิมพ์ด้วยเส้นทางเดิมเหมือนก่อนทุกประการ
 *   3. **ไม่แตะแม่แบบที่มีเวอร์ชันอยู่แล้ว** แม้เวอร์ชันนั้นจะเป็นฉบับร่าง
 *   4. เนื้อหาของเวอร์ชันคัดลอกจากค่าปัจจุบันของแม่แบบตรง ๆ ไม่มีการแต่งเติม
 *
 * รันซ้ำได้ปลอดภัย และ `--dry-run` ใช้ดูว่าจะเกิดอะไรก่อนลงมือ
 */
class BackfillDocumentTemplateVersions extends Command
{
    protected $signature = 'document:backfill-versions {--dry-run : แสดงผลที่จะเกิดโดยไม่เขียนฐานข้อมูล}';

    protected $description = 'สร้างเวอร์ชันแรก (มีผลตั้งแต่วันนี้) ให้แม่แบบเอกสารที่ยังไม่มีเวอร์ชันเลย';

    public function handle(): int
    {
        $missing = DocumentTemplate::query()->doesntHave('versions')->orderBy('id')->get();

        if ($missing->isEmpty()) {
            $this->info('แม่แบบทุกรายการมีเวอร์ชันครบแล้ว — ไม่มีอะไรต้องทำ');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'code', 'ชื่อ', 'วิธีเรนเดอร์'],
            $missing->map(fn (DocumentTemplate $template) => [
                $template->id, $template->code, $template->name, $template->render_mode,
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->warn(sprintf('ทดลองเท่านั้น — จะสร้างเวอร์ชันใหม่ %d รายการ มีผลตั้งแต่ %s', $missing->count(), now()->toDateString()));

            return self::SUCCESS;
        }

        DB::transaction(function () use ($missing): void {
            foreach ($missing as $template) {
                $template->createVersion();
            }
        }, 5);

        $this->info(sprintf(
            'สร้างเวอร์ชันแรกให้แม่แบบ %d รายการ มีผลตั้งแต่ %s — ไม่มีใบลาใดถูกแก้ และไม่มีการอ้างผลย้อนหลัง',
            $missing->count(),
            now()->toDateString(),
        ));

        $this->line('เวอร์ชันที่ใช้งานอยู่ทั้งระบบตอนนี้: '.DocumentTemplateVersion::where('status', DocumentTemplateVersion::STATUS_ACTIVE)->count().' รายการ');

        return self::SUCCESS;
    }
}
