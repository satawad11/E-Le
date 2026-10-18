<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * บันทึกว่าไฟล์หลักฐานถูกลบตามระยะเวลาเก็บรักษาเมื่อใด
 * ทำให้แยกได้ว่า "ไม่เคยแนบ" กับ "แนบแล้วแต่ลบตามรอบ" ต่างกัน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_evidences', function (Blueprint $table) {
            $table->timestamp('purged_at')->nullable()->after('storage_path');
        });
    }

    public function down(): void
    {
        Schema::table('leave_evidences', function (Blueprint $table) {
            $table->dropColumn('purged_at');
        });
    }
};
