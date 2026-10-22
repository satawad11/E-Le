<?php

/*
 * Manifest โมดูล personnel — กลุ่มเมนู "ข้อมูลบุคลากร" (PLAN §8.2 ข.) · CORE-02
 *
 * ชั้นข้อมูลที่เห็น (EnginePersonnelFieldAccess):
 *   public เสมอ · internal เมื่อมี profile.view ใน scope · confidential เมื่อมี profile.view_confidential ใน scope
 *   sensitive เปิดดูได้ผ่าน profile.reveal พร้อมเหตุผลเท่านั้น
 */
return [
    'code' => 'personnel',
    'name' => 'ข้อมูลบุคลากร',
    'version' => '1.0.0',

    'features' => [
        'profile' => ['name' => 'ประวัติบุคลากร', 'actions' => [
            'view' => ['name' => 'ดูประวัติบุคลากร (ชั้นทั่วไปและภายใน)', 'scopes' => ['own', 'unit', 'subtree', 'all', 'assigned']],
            'view_confidential' => ['name' => 'ดูข้อมูลชั้นลับ (วันเกิด ผู้ติดต่อฉุกเฉิน)', 'scopes' => ['own', 'unit', 'subtree', 'all'], 'requires' => ['profile.view'], 'risk' => 'medium'],
            'reveal' => ['name' => 'เปิดดูข้อมูลอ่อนไหวพร้อมเหตุผล', 'scopes' => ['own', 'subtree', 'all'], 'requires' => ['profile.view'], 'risk' => 'high'],
            'edit' => ['name' => 'แก้ไขข้อมูลบุคลากร (งานบุคคล)', 'scopes' => ['unit', 'subtree', 'all'], 'requires' => ['profile.view'], 'risk' => 'medium', 'rules' => ['hr_field_guard']],
        ]],
        'change_request' => ['name' => 'คำขอแก้ไขข้อมูล', 'actions' => [
            'create' => ['name' => 'ยื่นคำขอแก้ไขข้อมูลของตนเอง', 'scopes' => ['own']],
            'review' => ['name' => 'พิจารณาคำขอแก้ไขข้อมูล', 'scopes' => ['subtree', 'all'], 'requires' => ['profile.view'], 'risk' => 'medium', 'rules' => ['not_own', 'maker_checker']],
        ]],
        'assignment' => ['name' => 'คำสั่งแต่งตั้งและมอบหมาย', 'actions' => [
            'manage' => ['name' => 'บันทึกการแต่งตั้ง/สิ้นสุดตำแหน่ง', 'scopes' => ['subtree', 'all'], 'requires' => ['profile.view'], 'risk' => 'high', 'rules' => ['not_own']],
        ]],
        'report' => ['name' => 'รายงานบุคลากร', 'actions' => [
            'view' => ['name' => 'ดูรายงานบุคลากร', 'scopes' => ['subtree', 'all']],
        ]],
        'import' => ['name' => 'นำเข้าข้อมูล', 'actions' => [
            'manage' => ['name' => 'นำเข้าหน่วยงาน ตำแหน่ง บุคลากร จาก Excel', 'scopes' => ['all'], 'risk' => 'high'],
        ]],
    ],

    'templates' => [
        'staff' => [
            'name' => 'บุคลากร (ตนเอง)', 'kind' => 'positional',
            'description' => 'ทุกคนที่มีตำแหน่งหลัก — ดู/เปิดดู/ขอแก้ไขข้อมูลของตนเอง',
            'permissions' => [
                'personnel.profile.view' => 'own',
                'personnel.profile.view_confidential' => 'own',
                'personnel.profile.reveal' => 'own',
                'personnel.change_request.create' => 'own',
            ],
        ],
        'head' => [
            'name' => 'หัวหน้างาน/หัวหน้าแผนก/หัวหน้าสาขา (L3–L4)', 'kind' => 'positional',
            'permissions' => ['personnel.profile.view' => 'subtree'],
        ],
        'deputy' => [
            'name' => 'รองผู้อำนวยการ (L2)', 'kind' => 'positional',
            'permissions' => [
                'personnel.profile.view' => 'subtree',
                'personnel.profile.view_confidential' => 'subtree',
                'personnel.report.view' => 'subtree',
            ],
        ],
        'director' => [
            'name' => 'ผู้อำนวยการ (L1)', 'kind' => 'positional',
            'permissions' => [
                'personnel.profile.view' => 'all',
                'personnel.profile.view_confidential' => 'all',
                'personnel.report.view' => 'all',
            ],
        ],
        'hr_officer' => [
            'name' => 'งานบุคคล (ทะเบียนประวัติ)', 'kind' => 'functional', 'mfa' => true,
            'description' => 'มอบผ่าน role grant พร้อมเลขคำสั่งและวันหมดอายุ',
            'permissions' => [
                'personnel.profile.view' => 'all',
                'personnel.profile.view_confidential' => 'all',
                'personnel.profile.reveal' => 'all',
                'personnel.profile.edit' => 'all',
                'personnel.change_request.review' => 'all',
                'personnel.assignment.manage' => 'all',
                'personnel.report.view' => 'all',
                'personnel.import.manage' => 'all',
                'org.unit.manage' => 'all',
                'org.position.manage' => 'all',
                'access.account.view' => 'all',
                'workflow.definition.manage' => 'all',
            ],
        ],
    ],

    'defaults' => [
        'L1' => ['director'],
        'L2' => ['deputy'],
        'L3' => ['head'],
        'L4' => ['head'],
        'L5' => ['staff'],
    ],

    'menu_groups' => [
        ['code' => 'personnel', 'name' => 'ข้อมูลบุคลากร', 'order' => 10, 'icon' => 'users'],
    ],

    'menus' => [
        ['code' => 'personnel.list', 'group' => 'personnel', 'name' => 'รายชื่อบุคลากร', 'scoped_name' => 'บุคลากร{unit}', 'route' => '/personnel', 'order' => 1, 'phase' => 'R-06',
            'permission' => 'personnel.profile.view', 'scopes' => ['unit', 'subtree', 'all', 'assigned']],
        ['code' => 'personnel.show', 'group' => 'personnel', 'name' => 'ประวัติบุคลากร', 'route' => '/personnel/:uuid', 'in_menu' => false, 'phase' => 'R-06',
            'permission' => 'personnel.profile.view'],
        ['code' => 'personnel.change_requests', 'group' => 'personnel', 'name' => 'คำขอแก้ไขรอตรวจ', 'route' => '/personnel/change-requests', 'order' => 2, 'phase' => 'R-06',
            'permission' => 'personnel.change_request.review', 'badge' => 'personnel.change_requests.pending'],
        ['code' => 'personnel.reports', 'group' => 'personnel', 'name' => 'รายงานบุคลากร', 'route' => '/personnel/reports', 'order' => 6, 'phase' => 'R-06',
            'permission' => 'personnel.report.view', 'scopes' => ['subtree', 'all']],
    ],
];
