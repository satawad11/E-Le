<?php

/*
 * Manifest โมดูล notification (บางส่วน) — ประกาศสิทธิ์ที่เมนู "ช่องทางแจ้งเตือน" (PLAN §8.2 ซ. 4) ต้องใช้
 * ตาราง notification_channels และ feature อื่น ๆ เพิ่มใน R-08 (Q9)
 */
return [
    'code' => 'notification',
    'name' => 'การแจ้งเตือน',
    'version' => '0.1.0',

    'features' => [
        'channel' => ['name' => 'ช่องทางแจ้งเตือน', 'actions' => [
            'manage' => ['name' => 'ตั้งค่า LINE/Telegram/อีเมล', 'scopes' => ['all'], 'risk' => 'medium'],
        ]],
    ],
];
