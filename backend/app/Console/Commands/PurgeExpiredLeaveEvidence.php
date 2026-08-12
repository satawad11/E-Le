<?php

namespace App\Console\Commands;

use App\Models\LeaveEvidence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * ลบไฟล์หลักฐานประกอบการลาที่พ้นระยะเวลาเก็บรักษา
 *
 * ใบรับรองแพทย์ที่แนบมากับใบลาป่วยเป็น "ข้อมูลสุขภาพ" ซึ่งเป็นข้อมูลอ่อนไหวตาม ม.26
 * พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล จึงไม่ควรเก็บไว้เกินความจำเป็น
 *
 * ลบเฉพาะตัวไฟล์ ส่วนระเบียนในฐานข้อมูล (ชื่อไฟล์ ผู้อัปโหลด sha256) ยังอยู่ครบ
 * เพื่อให้ตรวจสอบย้อนหลังได้ว่าเคยมีการยื่นหลักฐานจริง
 */
class PurgeExpiredLeaveEvidence extends Command
{
    protected $signature = 'pdpa:purge-leave-evidence
        {--years=10 : เก็บรักษาไว้กี่ปีนับจากวันที่อัปโหลด}
        {--force : ยืนยันการลบจริง (ค่าเริ่มต้นเป็นการแสดงผลอย่างเดียว)}';

    protected $description = 'ลบไฟล์หลักฐานประกอบการลาที่พ้นระยะเวลาเก็บรักษาตามหลัก PDPA';

    public function handle(): int
    {
        $years = max(1, (int) $this->option('years'));
        $force = (bool) $this->option('force');
        $cutoff = now()->subYears($years);

        $expired = LeaveEvidence::whereNotNull('storage_path')
            ->where('created_at', '<', $cutoff)
            ->get();

        if ($expired->isEmpty()) {
            $this->info("ไม่มีไฟล์หลักฐานที่เก่ากว่า {$years} ปี");

            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($expired as $evidence) {
            if (! $force) {
                continue;
            }

            if (Storage::disk('local')->exists($evidence->storage_path)) {
                Storage::disk('local')->delete($evidence->storage_path);
            }
            // เก็บ metadata ไว้เป็นหลักฐานว่าเคยยื่น แต่ไม่เก็บตัวเอกสารแล้ว
            $evidence->forceFill(['storage_path' => null, 'purged_at' => now()])->save();
            $deleted++;
        }

        $this->table(
            ['สถานะ', 'จำนวนไฟล์', 'เก่ากว่า'],
            [[$force ? 'ลบแล้ว' : 'จะถูกลบ', $force ? $deleted : $expired->count(), $cutoff->format('d/m/').($cutoff->year + 543)]]
        );

        if (! $force) {
            $this->line('สั่งเพิ่ม --force เพื่อลบจริง (ลบแล้วกู้คืนไม่ได้)');
        }

        return self::SUCCESS;
    }
}
