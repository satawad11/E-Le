<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * บันทึกเวลาที่ผู้ใช้ "ลงทะเบียนและยืนยัน" แอป TOTP ของตนสำเร็จ (SoT §24)
 *
 * ความลับ TOTP ของระบบนี้คำนวณจาก APP_KEY กับ UUID ของผู้ใช้ จึงมีอยู่เสมอ
 * แม้ผู้ใช้จะไม่เคยตั้งค่าแอปเลย · หากไม่มีธงนี้ การยืนยันตัวตนซ้ำด้วย TOTP
 * จะเปิดให้ใครก็ตามที่ถือโทเคนไปแล้วผ่านด่านได้ทันทีโดยไม่ต้องรู้รหัสผ่าน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('totp_confirmed_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('totp_confirmed_at');
        });
    }
};
