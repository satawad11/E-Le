<?php

/*
 * Manifest โมดูล access — กลุ่มเมนู "บัญชีและสิทธิ์" (PLAN §8.2 ช.) · CORE-03/04
 *
 * R-05: `access.self.security` = ความปลอดภัยบัญชีของตนเอง (รหัสผ่าน MFA อุปกรณ์ · `/me/security`)
 * ให้ทุกคนที่มีตำแหน่งหลักผ่านชุดสิทธิ์ `access.self_service` (L5) — endpoint ของตนเองจึงต่อ engine เหมือนทุก route
 */
return [
    'code' => 'access',
    'name' => 'บัญชีและสิทธิ์',
    'version' => '1.0.0',

    'features' => [
        'self' => ['name' => 'ความปลอดภัยบัญชีของตนเอง', 'actions' => [
            'security' => ['name' => 'เปลี่ยนรหัสผ่าน ตั้งค่า MFA และดูอุปกรณ์ของตนเอง', 'scopes' => ['own']],
        ]],
        'account' => ['name' => 'บัญชีผู้ใช้', 'actions' => [
            'view' => ['name' => 'ดูบัญชีผู้ใช้และที่มาของสิทธิ์', 'scopes' => ['all']],
            'manage' => ['name' => 'ระงับ/ปลดล็อก/ปิดบัญชี', 'scopes' => ['all'], 'requires' => ['account.view'], 'risk' => 'high', 'rules' => ['not_own']],
        ]],
        'role' => ['name' => 'บทบาทและสิทธิ์', 'actions' => [
            'view' => ['name' => 'ดูชุดสิทธิ์และ Permission Matrix', 'scopes' => ['all']],
        ]],
        'template' => ['name' => 'ตั้งค่าสิทธิ์รายโมดูล', 'actions' => [
            'manage' => ['name' => 'แก้ชุดสิทธิ์และการจับคู่กับตำแหน่ง', 'scopes' => ['all'], 'requires' => ['role.view'], 'risk' => 'high', 'rules' => ['two_person']],
        ]],
        'grant' => ['name' => 'มอบสิทธิ์เชิงหน้าที่และสิทธิ์รายบุคคล', 'actions' => [
            'manage' => ['name' => 'มอบ/ถอน role grant และ override', 'scopes' => ['all'], 'requires' => ['account.view'], 'risk' => 'high', 'rules' => ['grant_ceiling', 'two_person', 'not_own']],
        ]],
        'change' => ['name' => 'คำขอเปลี่ยนสิทธิ์', 'actions' => [
            'approve' => ['name' => 'ยืนยันคำขอเปลี่ยนสิทธิ์ (ผู้ดูแลคนที่สอง)', 'scopes' => ['all'], 'risk' => 'high', 'rules' => ['maker_checker']],
        ]],
        'review' => ['name' => 'ทบทวนสิทธิ์รายภาคเรียน', 'actions' => [
            'confirm' => ['name' => 'ยืนยันสิทธิ์พิเศษของบุคลากรในหน่วย', 'scopes' => ['subtree', 'all'], 'rules' => ['not_own']],
            'manage' => ['name' => 'ออกรอบทบทวนและรับรองผล', 'scopes' => ['all']],
        ]],
    ],

    'templates' => [
        'self_service' => [
            'name' => 'ความปลอดภัยบัญชีของตนเอง (ทุกคน)', 'kind' => 'positional',
            'permissions' => ['access.self.security' => 'own'],
        ],
        'reviewer' => [
            'name' => 'ผู้ยืนยันการทบทวนสิทธิ์ของหน่วย (L1–L4)', 'kind' => 'positional',
            'permissions' => ['access.review.confirm' => 'subtree'],
        ],
        'change_approver' => [
            'name' => 'ผู้ยืนยันคำขอเปลี่ยนสิทธิ์ (ผอ.)', 'kind' => 'positional',
            'permissions' => ['access.change.approve' => 'all'],
        ],
    ],

    'defaults' => [
        'L1' => ['reviewer', 'change_approver'],
        'L2' => ['reviewer'],
        'L3' => ['reviewer'],
        'L4' => ['reviewer'],
        'L5' => ['self_service'],
    ],

    'menu_groups' => [
        ['code' => 'access', 'name' => 'บัญชีและสิทธิ์', 'order' => 90, 'icon' => 'shield'],
    ],

    'menus' => [
        ['code' => 'access.accounts', 'group' => 'access', 'name' => 'บัญชีผู้ใช้', 'route' => '/access/accounts', 'order' => 1, 'phase' => 'R-05',
            'permission' => 'access.account.view'],
        ['code' => 'access.account_detail', 'group' => 'access', 'name' => 'รายละเอียดบัญชีและ Simulator', 'route' => '/access/accounts/:uuid', 'in_menu' => false, 'phase' => 'R-07',
            'permission' => 'access.account.view'],
        ['code' => 'access.roles', 'group' => 'access', 'name' => 'บทบาทและสิทธิ์', 'route' => '/access/roles', 'order' => 2, 'phase' => 'R-07',
            'permission' => 'access.role.view'],
        ['code' => 'access.modules', 'group' => 'access', 'name' => 'ตั้งค่าสิทธิ์รายโมดูล', 'route' => '/access/modules', 'order' => 3, 'phase' => 'R-07',
            'permission' => 'access.template.manage'],
        ['code' => 'access.change_requests', 'group' => 'access', 'name' => 'คำขอเปลี่ยนสิทธิ์รอยืนยัน', 'route' => '/access/change-requests', 'order' => 4, 'phase' => 'R-07',
            'permission' => 'access.change.approve'],
        ['code' => 'access.reviews', 'group' => 'access', 'name' => 'ทบทวนสิทธิ์รายภาคเรียน', 'route' => '/access/reviews', 'order' => 5, 'phase' => 'R-05',
            'permission' => ['access.review.confirm', 'access.review.manage']],
        ['code' => 'access.audit', 'group' => 'access', 'name' => 'บันทึกการใช้งาน', 'route' => '/access/audit', 'order' => 6, 'phase' => 'R-07',
            'permission' => 'audit.log.view'],
    ],
];
