<?php

namespace App\Console\Commands;

use App\Support\AuditLogGuard;
use Illuminate\Console\Command;

/**
 * ติดตั้งหรือตรวจ trigger ที่ทำให้ประวัติการตรวจสอบแก้ไขหรือลบไม่ได้ (REDESIGN-2569 · D-07)
 *
 * ใช้เมื่อ migration ติดตั้งไม่ได้เพราะบัญชีฐานข้อมูลไม่มีสิทธิ์ หรือหลังเพิ่มคอลัมน์ในตาราง audit
 * (trigger อ่านรายชื่อคอลัมน์ตอนติดตั้ง) — รันซ้ำได้
 *
 *   DB_USERNAME=<บัญชีที่มีสิทธิ์> DB_PASSWORD=... php artisan audit:protect
 *   php artisan audit:protect --check     ตรวจอย่างเดียว คืนค่าล้มเหลวเมื่อยังมีตารางที่ไม่ได้ป้องกัน
 */
class ProtectAuditLogs extends Command
{
    protected $signature = 'audit:protect {--check : ตรวจอย่างเดียว ไม่ติดตั้ง}';

    protected $description = 'ติดตั้งหรือตรวจการป้องกันไม่ให้แก้ไขหรือลบประวัติการตรวจสอบที่ระดับฐานข้อมูล';

    public function handle(): int
    {
        if (! AuditLogGuard::supported()) {
            $this->error('ฐานข้อมูลชนิดนี้ยังไม่รองรับการป้องกันด้วย trigger');

            return self::FAILURE;
        }

        if (! $this->option('check')) {
            $result = AuditLogGuard::install();
            if ($result['failed'] !== []) {
                $this->error('ติดตั้งไม่สำเร็จ: '.implode(', ', $result['failed']));
                $this->line($result['reason']);

                return self::FAILURE;
            }
            $this->info('ติดตั้งการป้องกันแล้ว '.count($result['protected']).' ตาราง');
        }

        $missing = AuditLogGuard::unprotectedTables();
        if ($missing !== []) {
            $this->error('ตารางที่ยังไม่ได้ป้องกัน: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $this->info('ประวัติการตรวจสอบได้รับการป้องกันครบทุกตาราง');

        return self::SUCCESS;
    }
}
