<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INT-20 — ดัชนีที่ query จริงต้องใช้ (SoT §26 · สมรรถนะ)
 *
 * **เพิ่มเฉพาะดัชนีที่มี query จริงเรียกใช้** ไม่ใช่เพิ่มไว้ก่อนเผื่อ —
 * ดัชนีทุกตัวมีราคาตอนเขียน และดัชนีที่ไม่มีใครใช้คือค่าใช้จ่ายล้วน ๆ
 *
 * ที่มาของแต่ละตัว:
 *
 *   `created_by`            `DocumentController::index()` ทำ
 *                           `whereIn(owner_org_unit_id) orWhere(created_by)`
 *                           ครึ่งหลังของ OR ยังสแกนทั้งตารางอยู่
 *
 *   `(status, finalized_at)` รายงานทะเบียน (INT-19) และคิวการทำลาย กรองสองคอลัมน์นี้คู่กัน
 *
 *   `recipient_id`          กล่องหนังสือเข้า (INT-13) มีดัชนีคู่อยู่แล้ว แต่การนับ
 *                           "ยังไม่รับทราบ" กรองด้วย `acknowledged_at` ซึ่งยังไม่มีดัชนี
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saraban_documents', function (Blueprint $table) {
            $table->index('created_by', 'saraban_documents_created_by_index');
            $table->index(['status', 'finalized_at'], 'saraban_documents_status_finalized_index');
        });

        Schema::table('saraban_distribution_recipients', function (Blueprint $table) {
            $table->index(['recipient_id', 'acknowledged_at'], 'saraban_recipient_ack_index');
        });
    }

    public function down(): void
    {
        Schema::table('saraban_documents', function (Blueprint $table) {
            $table->dropIndex('saraban_documents_created_by_index');
            $table->dropIndex('saraban_documents_status_finalized_index');
        });

        Schema::table('saraban_distribution_recipients', function (Blueprint $table) {
            $table->dropIndex('saraban_recipient_ack_index');
        });
    }
};
