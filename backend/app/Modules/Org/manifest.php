<?php

/*
 * Manifest โมดูล org — โครงสร้างองค์กร ตำแหน่ง คำสั่งแต่งตั้ง (CORE-01) · เมนูอยู่ในกลุ่ม "ข้อมูลบุคลากร" (PLAN §8.2 ข. 3–5)
 */
return [
    'code' => 'org',
    'name' => 'โครงสร้างองค์กร',
    'version' => '1.0.0',

    'features' => [
        'unit' => ['name' => 'หน่วยงาน', 'actions' => [
            'view' => ['name' => 'ดูโครงสร้างองค์กร', 'scopes' => ['all']],
            'manage' => ['name' => 'เพิ่ม ย้าย ปิดหน่วยงาน', 'scopes' => ['all'], 'requires' => ['unit.view'], 'risk' => 'medium'],
        ]],
        'position' => ['name' => 'ตำแหน่ง', 'actions' => [
            'manage' => ['name' => 'จัดการทะเบียนตำแหน่ง', 'scopes' => ['all'], 'requires' => ['unit.view']],
        ]],
    ],

    'templates' => [
        'viewer' => [
            'name' => 'ดูโครงสร้างองค์กร', 'kind' => 'positional',
            'permissions' => ['org.unit.view' => 'all'],
        ],
    ],

    'defaults' => [
        'L5' => ['viewer'],
    ],

    'menus' => [
        ['code' => 'org.structure', 'group' => 'personnel', 'name' => 'โครงสร้างองค์กร', 'route' => '/org', 'order' => 3, 'phase' => 'R-03',
            'permission' => ['org.unit.view', 'org.unit.manage']],
        ['code' => 'org.positions', 'group' => 'personnel', 'name' => 'ตำแหน่ง', 'route' => '/org/positions', 'order' => 4, 'phase' => 'R-03',
            'permission' => 'org.position.manage'],
        ['code' => 'org.assignments', 'group' => 'personnel', 'name' => 'คำสั่งแต่งตั้งและมอบหมาย', 'route' => '/org/assignments', 'order' => 5, 'phase' => 'R-03',
            'permission' => 'personnel.assignment.manage'],
    ],
];
