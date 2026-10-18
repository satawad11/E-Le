<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เมื่อไฟล์หลักฐานถูกลบตามระยะเวลาเก็บรักษา ระเบียนจะไม่มี storage_path อีกต่อไป
 * แต่ยังต้องเก็บ metadata ไว้เป็นหลักฐานว่าเคยยื่นเอกสารจริง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_evidences', function (Blueprint $table) {
            $table->string('storage_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('leave_evidences', function (Blueprint $table) {
            $table->string('storage_path')->nullable(false)->change();
        });
    }
};
