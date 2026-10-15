<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ไฟล์แนบของหนังสือสารบรรณ
    |--------------------------------------------------------------------------
    |
    | SEC-023 — เดิมกฎตรวจไฟล์แนบมีเพียง `file|max:20480` ผู้ใช้ที่สร้างหนังสือได้
    | จึงอัปโหลดไฟล์ชนิดใดก็ได้ (.php, .html, .svg, .exe) เข้าสู่ storage ของระบบ
    |
    | ตอนนี้บังคับสามชั้น:
    |   1. `allowed_mime_types` — ตรวจจาก **เนื้อไฟล์** ไม่ใช่นามสกุลหรือ header
    |      ที่ client ส่งมา (กฎ `mimetypes:` ของ Laravel อ่าน magic bytes)
    |   2. `allowed_extensions` — ตรวจนามสกุลจริงของไฟล์ที่อัปโหลด (กฎ `extensions:`)
    |      กันไฟล์ที่ mime ผ่านแต่ตั้งชื่อเป็น .php เพื่อหลอก web server
    |   3. ชื่อไฟล์บนดิสก์เป็น UUID เสมอ ชื่อเดิมของผู้ใช้เก็บไว้เฉพาะเพื่อแสดงผล
    |      จึงไม่มีทางที่ชื่อไฟล์จากภายนอกไปกำหนด path หรือ นามสกุลบนดิสก์ได้
    |
    */

    'attachments' => [

        'max_size_kb' => (int) env('SARABAN_ATTACHMENT_MAX_KB', 20480),

        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'application/zip',
        ],

        'allowed_extensions' => [
            'pdf',
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'txt',
            'zip',
        ],

    ],

];
