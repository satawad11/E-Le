<?php

namespace App\Services\DocumentEngine;

use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * ประเภทการลาใดออกเอกสารด้วย Document Engine ได้บ้าง
 *
 * เดิมค่านี้อยู่ใน `.env` (`DOCUMENT_ENGINE_LEAVE_TYPES`) อย่างเดียว ผู้ดูแลจึงเปิดใช้ทีละ
 * ประเภทเองไม่ได้ ต้องแก้ไฟล์บนเครื่องแม่ข่ายและรีสตาร์ต และไม่มีบันทึกว่าใครเปิดเมื่อใด
 *
 * โครงสร้างการตัดสินมีสองชั้นตามเดิม:
 *   1. `document_engine.enabled` (`.env`) = สวิตช์ตัดตอนระดับระบบ ปิดแล้วทุกอย่างใช้เส้นทางเดิม
 *   2. รายการประเภทการลาที่เปิด — อ่านจากฐานข้อมูลก่อน ถ้ายังไม่เคยตั้งค่าจึงถอยไปใช้ค่าใน `.env`
 *
 * ชั้นแรกยังอยู่ใน `.env` โดยตั้งใจ เพื่อให้ถอยกลับได้ทันทีโดยไม่ต้องพึ่งฐานข้อมูลหรือหน้าจอ
 */
class EngineActivation
{
    public const SETTING_KEY = 'document_engine_leave_types';

    public function __construct(private readonly AuditLogger $audit) {}

    /** ระบบเปิด engine ไว้หรือไม่ (สวิตช์ตัดตอนระดับบนสุด) */
    public function systemEnabled(): bool
    {
        return (bool) config('document_engine.enabled', false);
    }

    /**
     * รายการที่เปิดใช้ — `['*']` คือทุกประเภท
     *
     * @return array<int, string>
     */
    public function enabledLeaveTypes(): array
    {
        $stored = Setting::where('key', self::SETTING_KEY)->value('value');

        if ($stored === null) {
            return array_values(array_map('strval', (array) config('document_engine.entities.leave_types', [])));
        }

        $decoded = json_decode((string) $stored, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    public function handles(int $leaveTypeId): bool
    {
        if (! $this->systemEnabled()) {
            return false;
        }

        $allowed = $this->enabledLeaveTypes();

        return in_array('*', $allowed, true) || in_array((string) $leaveTypeId, $allowed, true);
    }

    /**
     * ตั้งรายการประเภทการลาที่เปิดใช้ พร้อมบันทึกว่าใครเปลี่ยนอะไร
     *
     * @param  array<int, int|string>  $leaveTypes
     * @return array<int, string>
     */
    public function setEnabledLeaveTypes(array $leaveTypes, ?User $actor = null): array
    {
        $before = $this->enabledLeaveTypes();
        $after = array_values(array_unique(array_map('strval', $leaveTypes)));

        Setting::updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => json_encode($after, JSON_UNESCAPED_UNICODE), 'type' => 'json'],
        );

        $this->audit->log(
            action: 'document_engine_entities_changed',
            resource: 'DocumentEngine',
            resourceId: null,
            before: ['leave_types' => $before],
            after: ['leave_types' => $after],
            actorId: $actor?->id,
        );

        return $after;
    }
}
