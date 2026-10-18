<?php

use App\Models\Saraban\Document;
use App\Services\Workflow\SarabanWorkflowProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * CORE CHECKPOINT · CORE-WF-002 — เปิด workflow version pinning ให้งานสารบรรณ
 *
 * ก่อนหน้านี้สายงานหนังสือราชการไม่มี version เลย การแก้ template สายงานจึง
 * กระทบเอกสารที่กำลังเดินอยู่ทันที ซึ่งขัดกับ ADR-002 และ `docs/07`
 *
 * LEGACY-05 (05-5) เตรียมสายงานไว้เป็น `draft` แล้วแต่เปิดใช้ไม่ได้ เพราะ
 * ยังไม่มีผู้อนุมัติในสาย — `saraban.document.approve` เป็นของ Super Admin เท่านั้น
 * migration `2026_08_14_220000` แก้ข้อนั้นไปแล้ว จึงเปิดใช้ได้
 *
 * additive ล้วน — ไม่แตะ `saraban_documents`, `saraban_document_workflow_steps`
 * หรือ `saraban_document_workflow_templates` แม้แต่แถวเดียว
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflow_definitions') || ! Schema::hasTable('saraban_documents')) {
            return;
        }

        $provisioner = app(SarabanWorkflowProvisioner::class);
        $provisioner->publishCurrentVersion();

        // ผูกเอกสารที่มีอยู่แล้วเข้ากับเวอร์ชันปัจจุบัน (อ่านอย่างเดียว ไม่แก้เอกสาร)
        Document::query()->orderBy('id')->chunkById(200, function ($documents) use ($provisioner): void {
            foreach ($documents as $document) {
                $provisioner->pin($document);
            }
        });
    }

    public function down(): void
    {
        // ไม่ถอนอะไร — ตาราง workflow ถูกลบโดย migration ที่สร้างมันอยู่แล้ว
    }
};
