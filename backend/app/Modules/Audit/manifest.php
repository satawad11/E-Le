<?php

/*
 * Manifest โมดูล audit — บันทึกการใช้งาน (REQ-AUD-01) · เมนูอยู่ในกลุ่ม "บัญชีและสิทธิ์" (PLAN §8.2 ช. 6)
 *
 * ผู้ดูแลระบบและผู้ตรวจสอบไม่เห็นข้อมูลบุคลากรของผู้อื่น (PROJECT_RULES ข้อ 8) — ชุดสิทธิ์ประกาศ denies
 * เพดาน `own` ให้ personnel.* และปฏิเสธ org.* ทั้งหมด จึงเหลือเฉพาะเมนูส่วนบุคคลของตนเอง (PLAN §8.3)
 */
return [
    'code' => 'audit',
    'name' => 'บันทึกการใช้งาน',
    'version' => '1.0.0',

    'features' => [
        'log' => ['name' => 'บันทึกการใช้งาน', 'actions' => [
            'view' => ['name' => 'ดูบันทึกการใช้งาน (อ่านอย่างเดียว)', 'scopes' => ['all']],
        ]],
    ],

    'templates' => [
        'auditor' => [
            'name' => 'ผู้ตรวจสอบ', 'kind' => 'functional',
            'description' => 'อ่านบัญชี ชุดสิทธิ์ บันทึกการใช้งาน และออกรอบทบทวนสิทธิ์ — ไม่เห็นข้อมูลบุคลากร',
            'permissions' => [
                'access.account.view' => 'all',
                'access.role.view' => 'all',
                'access.review.manage' => 'all',
                'audit.log.view' => 'all',
            ],
            'denies' => ['personnel.*' => 'own', 'org.*' => null],
        ],
    ],

    'menus' => [],
];
