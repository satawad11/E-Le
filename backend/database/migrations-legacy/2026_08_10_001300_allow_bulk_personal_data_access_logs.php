<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * รายงานรวม (เช่น คัดกรองเลื่อนเงินเดือน) เข้าถึงข้อมูลของบุคลากรหลายคนพร้อมกัน
 * จึงไม่มีเจ้าของข้อมูลรายเดียว — เก็บ subject_id เป็น null แล้วอธิบายไว้ใน purpose แทน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_data_access_logs', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable()->change();
            $table->unsignedInteger('subject_count')->nullable()->after('subject_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_data_access_logs', function (Blueprint $table) {
            $table->dropColumn('subject_count');
            $table->foreignId('subject_id')->nullable(false)->change();
        });
    }
};
