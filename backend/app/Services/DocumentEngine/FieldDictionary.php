<?php

namespace App\Services\DocumentEngine;

use App\Models\Document\DocumentFieldDefinition;

/**
 * ซิงก์ Field Dictionary จาก catalog ของ provider ที่ประกาศในโค้ด
 *
 * เรียกทั้งจาก seeder และทุกครั้งที่หน้าออกแบบขอรายการฟิลด์ — ช่องกรอกที่ผู้ดูแลเพิ่มให้ประเภทการลา
 * (extra.*) จึงขึ้นในหน้าออกแบบทันทีโดยไม่ต้องรัน seeder ใหม่
 *
 * ตัวแปรชุดเดิมของแม่แบบ HTML (`school_name`, `user_name` ฯลฯ — ไม่มีจุด) ถูกปิดจากรายการ
 * เพราะแม่แบบ HTML แก้จากหน้าจอไม่ได้แล้ว (TPL-SEC-01) ค่ายังส่งให้ engine เหมือนเดิม
 */
class FieldDictionary
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function sync(): void
    {
        $codes = [];

        foreach ($this->providers->all() as $source => $class) {
            $provider = app($class);

            foreach ($provider->catalog() as $field) {
                $codes[] = $field['code'];

                DocumentFieldDefinition::updateOrCreate(
                    ['code' => $field['code']],
                    [
                        'label_th' => $field['label_th'],
                        'group_th' => $field['group_th'] ?? null,
                        'data_type' => $field['data_type'] ?? 'TEXT',
                        'source' => $source,
                        'module' => $provider->module(),
                        'format' => $field['format'] ?? null,
                        'is_active' => true,
                    ],
                );
            }
        }

        DocumentFieldDefinition::query()
            ->where('module', 'leave')
            ->where('code', 'not like', '%.%')
            ->where('is_active', true)
            ->update(['is_active' => false]);

        // ช่องเฉพาะแบบฟอร์มที่ถูกลบออกจากประเภทการลาแล้ว ไม่ต้องให้เลือกวางอีก (แบบฟอร์มเก่าที่วางไว้ยังพิมพ์ได้)
        DocumentFieldDefinition::query()
            ->where('code', 'like', 'extra.%')
            ->whereNotIn('code', $codes)
            ->update(['is_active' => false]);
    }
}
