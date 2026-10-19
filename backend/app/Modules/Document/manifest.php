<?php

/*
 * Manifest โมดูล document (บางส่วน) — ประกาศสิทธิ์ที่เมนู "ศูนย์เอกสารและแม่แบบ" (PLAN §8.2 ซ. 3) ต้องใช้
 * feature อื่น ๆ ของ document engine เพิ่มใน R-08
 */
return [
    'code' => 'document',
    'name' => 'ศูนย์เอกสารและแม่แบบ',
    'version' => '0.1.0',

    'features' => [
        'template' => ['name' => 'แม่แบบเอกสาร', 'actions' => [
            'manage' => ['name' => 'จัดการแม่แบบ เวอร์ชัน และตำแหน่งฟิลด์', 'scopes' => ['all'], 'risk' => 'medium'],
        ]],
    ],
];
