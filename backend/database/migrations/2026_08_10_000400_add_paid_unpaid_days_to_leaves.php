<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ระเบียบการลาแยก "วันที่ได้รับเงินเดือน" กับ "วันที่ไม่ได้รับเงินเดือน"
 * เช่น ลาป่วยของข้าราชการ: 60 วันทำการแรกได้รับเงินเดือน ส่วนที่เกินผู้บังคับบัญชา
 * อนุญาตเพิ่มได้อีกไม่เกิน 60 วันโดยไม่ได้รับเงินเดือน
 * เก็บเป็นตัวเลขบนใบลา (snapshot ตอนยื่น) เพื่อใช้ในบัตรลาและงานการเงิน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->decimal('paid_days', 7, 2)->default(0)->after('total_days');
            $table->decimal('unpaid_days', 7, 2)->default(0)->after('paid_days');
        });

        // ใบลาเดิมทั้งหมดถือว่าได้รับเงินเดือนเต็มจำนวน (ยังไม่เคยมีการบังคับเพดานนี้มาก่อน)
        Schema::hasColumn('leaves', 'paid_days') && DB::table('leaves')
            ->update(['paid_days' => DB::raw('total_days')]);
    }

    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->dropColumn(['paid_days', 'unpaid_days']);
        });
    }
};
