<?php

namespace App\Console\Commands;

use App\Models\Document\DocumentFieldDefinition;
use App\Services\DocumentEngine\FieldDictionarySynchronizer;
use Illuminate\Console\Command;

/**
 * ทำให้ Field Dictionary ตรงกับสิ่งที่ระบบมีค่าให้จริง
 *
 * หน้าศูนย์จัดการเอกสารซิงก์ให้อยู่แล้วตอนเปิดหน้า คำสั่งนี้มีไว้สำหรับการติดตั้ง/อัปเดต
 * ที่ต้องการให้ข้อมูลพร้อมก่อนมีคนเปิดหน้าจอ และเพื่อตรวจผลได้จากบรรทัดคำสั่ง
 *
 * ไม่ลบฟิลด์ใดทิ้ง (ปิดการใช้งานแทน) และไม่เขียนทับป้ายชื่อ/กลุ่มที่ผู้ดูแลตั้งเอง
 */
class SyncDocumentFields extends Command
{
    protected $signature = 'document:sync-fields';

    protected $description = 'ซิงก์พจนานุกรมตัวแปรของแบบฟอร์มให้ตรงกับประเภทการลา ช่องเฉพาะ และสายอนุมัติที่ตั้งไว้จริง';

    public function handle(FieldDictionarySynchronizer $synchronizer): int
    {
        $before = DocumentFieldDefinition::where('is_active', true)->count();

        $synchronizer->sync();

        $after = DocumentFieldDefinition::where('is_active', true)->count();

        $this->info(sprintf('ซิงก์ตัวแปรเรียบร้อย — ใช้งานอยู่ %d รายการ (เดิม %d)', $after, $before));

        return self::SUCCESS;
    }
}
