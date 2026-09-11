<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * รหัสผ่านที่ผู้ดูแลตั้งให้ ต้องเป็นรหัสชั่วคราวเสมอ
 *
 * **ที่มา:** หน้าจัดการผู้ใช้ให้ผู้ดูแลพิมพ์รหัสผ่านของบุคลากรเองทั้งตอนสร้างบัญชี
 * และตอนรีเซ็ต · ผู้ดูแลจึงรู้รหัสผ่านที่บุคลากรใช้เข้าระบบจริง และไม่มีอะไรบังคับ
 * ให้เปลี่ยน รหัสนั้นจึงกลายเป็นรหัสถาวรของเจ้าตัวไปเลย
 *
 * ผลที่ตามมาคือ audit log ตอบไม่ได้ว่าใครเป็นคนทำรายการจริง — ถ้าผู้ดูแลกับเจ้าของ
 * บัญชีรู้รหัสเดียวกัน การกระทำในระบบก็ชี้ตัวคนทำไม่ได้ ซึ่งเป็นหัวใจของ audit trail
 *
 * ที่น่าสังเกตคือ **การนำเข้าด้วย CSV ทำถูกอยู่แล้ว** — สุ่มรหัสผ่าน ไม่ส่งกลับ
 * และให้ผู้ใช้ตั้งเองผ่าน "ลืมรหัสผ่าน" · คอลัมน์นี้ทำให้ทุกทางเข้าใช้หลักเดียวกัน
 *
 * `must_change_password` = ต้องเปลี่ยนรหัสผ่านก่อนใช้งานระบบต่อ · ตั้งเมื่อผู้ดูแล
 * สร้างบัญชีหรือรีเซ็ตรหัสให้ และล้างเมื่อเจ้าตัวเปลี่ยนรหัสด้วยตนเอง
 *
 * บัญชีที่มีอยู่แล้วไม่ถูกบังคับย้อนหลัง — ค่าเริ่มต้นเป็น false เพื่อไม่ให้ทุกคน
 * ในระบบถูกขวางพร้อมกันในเช้าวันที่อัปเดต
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'must_change_password')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'must_change_password')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('must_change_password');
        });
    }
};
