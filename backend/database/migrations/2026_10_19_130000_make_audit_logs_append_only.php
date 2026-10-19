<?php

use App\Support\AuditLogGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * ประวัติการตรวจสอบเพิ่มได้อย่างเดียว ห้ามแก้หรือลบ — บังคับที่ระดับฐานข้อมูล
 * (REDESIGN-2569 เฟส 5.5 · D-07 เจ้าของระบบตัดสิน 18/10/2569: Super Admin ก็แก้ audit log ไม่ได้)
 *
 * PROJECT_RULES ข้อ 8 กำหนดให้ audit log เป็น append-oriented แต่เดิมบังคับแค่ในโค้ด — รายละเอียดใน AuditLogGuard
 *
 * ถ้าบัญชีฐานข้อมูลที่รัน migration ไม่มีสิทธิ์สร้าง trigger (เช่น MySQL ที่เปิด binary log) migration นี้
 * ไม่ทำให้การ deploy ล้ม แต่บันทึกคำเตือน และ `php artisan audit:protect --check` จะรายงานว่ายังไม่ได้ป้องกัน
 * ให้ผู้ดูแลฐานข้อมูลรัน `php artisan audit:protect` ด้วยบัญชีที่มีสิทธิ์
 */
return new class extends Migration
{
    public function up(): void
    {
        $result = AuditLogGuard::install();

        if ($result['failed'] !== []) {
            $message = 'ยังไม่ได้ป้องกันการแก้ไขประวัติการตรวจสอบ: '.implode(', ', $result['failed'])
                .' — '.$result['reason'].' ให้รัน php artisan audit:protect ด้วยบัญชีฐานข้อมูลที่มีสิทธิ์';
            Log::warning($message);
            if (app()->runningInConsole()) {
                fwrite(STDERR, PHP_EOL.'  คำเตือน: '.$message.PHP_EOL);
            }
        }
    }

    public function down(): void
    {
        AuditLogGuard::remove();
    }
};
