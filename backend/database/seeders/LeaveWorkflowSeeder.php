<?php

namespace Database\Seeders;

use App\Models\ApprovalStep;
use App\Services\Workflow\LeaveWorkflowProvisioner;
use Illuminate\Database\Seeder;

/**
 * LEGACY-05 · 05-2 — ออก Leave Workflow v1 สำหรับการติดตั้งใหม่
 *
 * ต้องรันหลัง `approval_steps` มีข้อมูลแล้ว เพราะ v1 ถูกแปลงมาจากตารางนั้น
 * idempotent — รันซ้ำไม่ออกเวอร์ชันใหม่ถ้าสายอนุมัติไม่เปลี่ยน
 */
class LeaveWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        if (! ApprovalStep::exists()) {
            $this->command?->warn('ยังไม่มี approval_steps — ข้ามการสร้าง Leave Workflow v1');

            return;
        }

        $version = app(LeaveWorkflowProvisioner::class)->publishCurrentVersion();

        $this->command?->info("Leave Workflow v{$version->version} พร้อมใช้งาน");
    }
}
