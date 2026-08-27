<?php

/*
|--------------------------------------------------------------------------
| Allowlist ของไฟล์ที่ผู้ใช้อัปโหลดได้ (SEC-023R)
|--------------------------------------------------------------------------
|
| เดิม allowlist สองชั้น (ชนิดไฟล์จริง + นามสกุล) มีอยู่ที่ `config/saraban.php`
| ที่เดียว และหายไปพร้อมกับโมดูลสารบรรณ (ADR-MODULE-01) ส่วนที่เหลือของระบบ
| ตรวจด้วย `mimes:` อย่างเดียวบ้าง ปล่อย `svg`/`gif` บ้าง แล้วแต่จุด
|
| ไฟล์นี้เป็นแหล่งความจริงเดียวของ allowlist ให้ทุกจุดที่รับไฟล์ ใช้ผ่าน
| `App\Support\UploadRules` เพื่อให้กฎที่บังคับใช้จริงเหมือนกันทั้งระบบ
|
| หลักการ: ต้องไม่มีชนิดไฟล์ที่ "รันได้เมื่อเบราว์เซอร์เปิด" อยู่ใน allowlist
| (`html`, `svg`, `php`, `js`, `exe` ฯลฯ) แม้ไฟล์จะถูกเสิร์ฟผ่านเส้นทางที่ต้อง
| ยืนยันตัวตนและตั้ง `Content-Type` เองแล้วก็ตาม — เป็นการป้องกันซ้อนชั้น
|
*/

return [

    /*
     * เอกสารแนบของผู้ใช้ — หลักฐานประกอบการลา / ไฟล์แนบใบลา
     *
     * `mime_types` ตรวจจาก magic bytes ของเนื้อไฟล์ (กฎ `mimetypes:`)
     * `extensions` ตรวจนามสกุลจริงของไฟล์ที่อัปโหลด (กฎ `extensions:`)
     * ต้องผ่านทั้งสองชั้น ไฟล์ที่เปลี่ยนนามสกุลหลอกจึงไม่ผ่าน
     */
    'attachments' => [
        'mime_types' => ['application/pdf', 'image/jpeg', 'image/png'],
        'extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
        'max_size_kb' => 10240,
    ],

    /*
     * รูปภาพของระบบ — โลโก้สถานศึกษา ตราสัญลักษณ์บนเอกสาร
     */
    'images' => [
        'mime_types' => ['image/jpeg', 'image/png'],
        'extensions' => ['jpg', 'jpeg', 'png'],
        'max_size_kb' => 4096,
    ],

    /*
     * แม่แบบเอกสารของ Document Engine — PDF เท่านั้น
     */
    'document_templates' => [
        'mime_types' => ['application/pdf'],
        'extensions' => ['pdf'],
        'max_size_kb' => 20480,
    ],

    /*
     * ชนิดที่ห้ามปรากฏใน allowlist ใดเลย — ใช้เป็นด่านของเทสต์
     * เพื่อให้การเพิ่มชนิดไฟล์ในอนาคตชนกฎนี้ก่อนขึ้น production
     */
    'never_allowed' => [
        'mime_types' => [
            'text/html',
            'image/svg+xml',
            'application/x-httpd-php',
            'application/x-msdownload',
            'application/javascript',
            'text/javascript',
            'application/x-sh',
        ],
        'extensions' => [
            'php', 'phtml', 'phar', 'html', 'htm', 'svg',
            'exe', 'sh', 'bat', 'js', 'mjs', 'jsp', 'asp', 'aspx',
        ],
    ],

];
