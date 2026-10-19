<?php

/*
 * Manifest โมดูล core — กลุ่มเมนู "ส่วนบุคคล" (PLAN §8.2 ก.) · ทุกคนเห็น
 * รูปแบบ manifest: docs/08-MODULE-STANDARDS.md · PLAN §3.4
 */
return [
    'code' => 'core',
    'name' => 'ส่วนบุคคล',
    'version' => '1.0.0',

    'features' => [],

    'menu_groups' => [
        ['code' => 'core', 'name' => 'ส่วนบุคคล', 'order' => 0, 'icon' => 'home'],
    ],

    'menus' => [
        ['code' => 'core.home', 'group' => 'core', 'name' => 'หน้าหลัก', 'route' => '/', 'order' => 1, 'phase' => 'R-06'],
        ['code' => 'core.inbox', 'group' => 'core', 'name' => 'งานรออนุมัติของฉัน', 'route' => '/inbox', 'order' => 2, 'phase' => 'R-08',
            'permission_pattern' => ['*.*.approve', '*.*.review']],
        ['code' => 'core.me', 'group' => 'core', 'name' => 'ข้อมูลของฉัน', 'route' => '/me', 'order' => 3, 'phase' => 'R-06',
            'permission' => 'personnel.profile.view', 'scopes' => ['own']],
        ['code' => 'core.me.access_history', 'group' => 'core', 'parent' => 'core.me', 'name' => 'ประวัติการเข้าถึงข้อมูลของฉัน', 'route' => '/me/access-history', 'order' => 1, 'phase' => 'R-06',
            'permission' => 'personnel.profile.view', 'scopes' => ['own']],
        ['code' => 'core.notifications', 'group' => 'core', 'name' => 'การแจ้งเตือน', 'route' => '/notifications', 'order' => 4, 'phase' => 'R-08'],
    ],
];
