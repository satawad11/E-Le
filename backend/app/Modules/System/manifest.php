<?php

/*
 * Manifest โมดูล system — กลุ่มเมนู "ตั้งค่าระบบ" (PLAN §8.2 ซ.)
 *
 * เมนูข้อ 2–4 อ้างสิทธิ์ของโมดูล workflow, document, notification ซึ่งประกาศ permission ไว้แล้ว
 * และจะได้ feature ครบเมื่อย้ายโมดูลใน R-08
 */
return [
    'code' => 'system',
    'name' => 'ตั้งค่าระบบ',
    'version' => '1.0.0',

    'features' => [
        'module' => ['name' => 'โมดูลในระบบ', 'actions' => [
            'manage' => ['name' => 'เปิด/ปิดโมดูล และ sync manifest', 'scopes' => ['all'], 'risk' => 'high', 'rules' => ['two_person']],
        ]],
        'setting' => ['name' => 'ตั้งค่าทั่วไป', 'actions' => [
            'manage' => ['name' => 'แก้ค่าตั้งของระบบ', 'scopes' => ['all'], 'risk' => 'high'],
        ]],
    ],

    'templates' => [
        'admin' => [
            'name' => 'ผู้ดูแลระบบ', 'kind' => 'functional',
            'description' => 'ดูแลระบบและสิทธิ์ ไม่อยู่ในสายอนุมัติ และไม่เห็นข้อมูลบุคลากรของผู้อื่น (PROJECT_RULES ข้อ 8)',
            'permissions' => [
                'system.module.manage' => 'all',
                'system.setting.manage' => 'all',
                'workflow.definition.manage' => 'all',
                'document.template.manage' => 'all',
                'notification.channel.manage' => 'all',
                'access.account.view' => 'all',
                'access.account.manage' => 'all',
                'access.role.view' => 'all',
                'access.template.manage' => 'all',
                'access.grant.manage' => 'all',
                'access.change.approve' => 'all',
                'access.review.manage' => 'all',
                'audit.log.view' => 'all',
            ],
            'denies' => ['personnel.*' => 'own', 'org.*' => null],
        ],
    ],

    'menu_groups' => [
        ['code' => 'system', 'name' => 'ตั้งค่าระบบ', 'order' => 99, 'icon' => 'settings'],
    ],

    'menus' => [
        ['code' => 'system.modules', 'group' => 'system', 'name' => 'โมดูลในระบบ', 'route' => '/system/modules', 'order' => 1, 'phase' => 'R-04',
            'permission' => 'system.module.manage'],
        ['code' => 'system.workflows', 'group' => 'system', 'name' => 'สายอนุมัติ', 'route' => '/system/workflows', 'order' => 2, 'phase' => 'R-08',
            'permission' => 'workflow.definition.manage'],
        ['code' => 'system.documents', 'group' => 'system', 'name' => 'ศูนย์เอกสารและแม่แบบ', 'route' => '/system/documents', 'order' => 3, 'phase' => 'R-08',
            'permission' => 'document.template.manage'],
        ['code' => 'system.notifications', 'group' => 'system', 'name' => 'ช่องทางแจ้งเตือน', 'route' => '/system/notifications', 'order' => 4, 'phase' => 'R-08',
            'permission' => 'notification.channel.manage'],
        ['code' => 'system.settings', 'group' => 'system', 'name' => 'ตั้งค่าทั่วไป', 'route' => '/system/settings', 'order' => 5, 'phase' => 'R-04',
            'permission' => 'system.setting.manage'],
    ],
];
