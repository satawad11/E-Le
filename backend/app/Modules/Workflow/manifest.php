<?php

/*
 * Manifest โมดูล workflow (บางส่วน) — ประกาศสิทธิ์ที่เมนู "สายอนุมัติ" (PLAN §8.2 ซ. 2) ต้องใช้
 * feature อื่น ๆ ของ workflow engine เพิ่มใน R-08
 */
return [
    'code' => 'workflow',
    'name' => 'สายอนุมัติ',
    'version' => '0.1.0',

    'features' => [
        'definition' => ['name' => 'นิยามสายอนุมัติ', 'actions' => [
            'manage' => ['name' => 'แก้สายอนุมัติ (ผอ. อนุมัติ)', 'scopes' => ['all'], 'risk' => 'high', 'rules' => ['two_person']],
        ]],
    ],
];
