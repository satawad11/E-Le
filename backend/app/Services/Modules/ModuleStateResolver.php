<?php

namespace App\Services\Modules;

use App\Services\SystemSettingService;

/**
 * นำสถานะเปิด-ปิดโมดูลที่ผู้ดูแลตั้งไว้ มาเป็นค่าที่ระบบใช้จริง
 *
 * CLEANUP-02 — เดิมการเปิด-ปิดโมดูลมีสองกลไกที่ไม่คุยกัน
 *
 *   `config/modules.*.enabled`   ตั้งจาก env เท่านั้น แต่เป็นตัวที่เมนู ด่าน route
 *                                และ Notification Center ใช้จริง
 *   `enable_leave_menu` ฯลฯ      ค่าตั้งค่าที่หน้า "โมดูลระบบ" เขียน แต่ไม่มีอะไรอ่าน
 *                                อีกเลยหลังแถบเมนูเปลี่ยนไปอ่านจากตาราง `menus`
 *
 * คลาสนี้ทำให้เหลือกลไกเดียว: ค่าตั้งค่า `module_{configKey}_enabled` เติมทับ config
 * ตอน boot ผู้ดูแลจึงเปิด-ปิดโมดูลได้จากหน้าจอ โดยไม่ต้องแก้ env และไม่ต้อง deploy ใหม่
 *
 * ค่าที่ตั้งด้วย `config([...])` ภายหลัง (เช่นในชุดทดสอบ) ยังชนะเสมอ เพราะทำหลัง boot
 */
class ModuleStateResolver
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function applyToConfig(): void
    {
        $values = $this->settings->runtimeValues();

        foreach (array_keys((array) config('modules', [])) as $configKey) {
            $override = $values->get("module_{$configKey}_enabled");

            if ($override === null || $override === '') {
                continue;
            }

            config(["modules.{$configKey}.enabled" => (bool) (int) $override]);
        }
    }
}
