<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ลบผู้ใช้แล้วประวัติการลาต้องไม่หายตามไปด้วย
 *
 * **ที่มา:** `DELETE /admin/users/{id}` ลบแถวจริง และ foreign key ที่ชี้มาที่ `users`
 * ตั้งเป็น CASCADE หลายตัว — `leaves`, `leave_balances`, `attendances`,
 * `leave_approvals` · ลบบุคลากรหนึ่งคนจึงพา **ใบลาทั้งหมดของเขา สิทธิวันลา และ
 * ประวัติการลงเวลา** หายไปถาวรในคำสั่งเดียว
 *
 * ที่หนักกว่านั้นคือแถวใน `leave_approvals` ที่คนนั้นเป็น *ผู้อนุมัติ* ก็หายด้วย —
 * ใบลาของ **คนอื่น** จะอธิบายไม่ได้ว่าใครเป็นผู้อนุมัติ ทั้งที่เจ้าของใบไม่ได้เกี่ยวอะไร
 *
 * ทะเบียนการลาของราชการเป็นเอกสารที่ต้องเก็บตามระเบียบ การกดปุ่มผิดครั้งเดียว
 * ไม่ควรลบหลักฐานเหล่านี้ได้
 *
 * เปลี่ยนเป็น soft delete: แถวยังอยู่ CASCADE จึงไม่ทำงาน ประวัติทั้งหมดคงอยู่
 * และผู้ดูแลกู้บัญชีคืนได้ · ด่านฝั่งแอปปฏิเสธการลบเมื่อยังมีเอกสารอ้างถึงอยู่
 * พร้อมบอกจำนวน และชี้ให้ใช้ "ระงับการใช้งาน" แทน
 *
 * **ทำไมตั้งชื่อไฟล์ให้รันทันทีหลังสร้างตาราง `users`** — โมเดล `User` ใช้
 * `SoftDeletes` แล้ว ทุก query ผ่านโมเดลจึงเติมเงื่อนไข `deleted_at is null`
 * เสมอ รวมถึง migration และ seeder รุ่นก่อนหน้าที่เรียก `User` ระหว่างติดตั้ง
 * ถ้าคอลัมน์นี้มาทีหลัง การติดตั้งใหม่จะล้มกลางคัน · ระบบที่ติดตั้งไปแล้วยังได้รับ
 * migration นี้ตามปกติ เพราะ Laravel รันเฉพาะรายการที่ยังไม่เคยรัน
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'deleted_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->softDeletes();
            // รายการผู้ใช้กรอง "ยังไม่ถูกลบ" ทุกครั้ง จึงต้องมี index ให้ query ใช้
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'deleted_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};
