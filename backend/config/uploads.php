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
     * ลายเซ็นอิเล็กทรอนิกส์ (SEC-028)
     *
     * รับได้ทั้งไฟล์ที่อัปโหลดและภาพ Base64 ที่วาดสดบนหน้าจอ
     * เพดานเล็กกว่าไฟล์แนบมาก เพราะลายเซ็นเป็นภาพลายเส้นขนาดเล็ก
     * ค่านี้ใช้จำกัดขนาดสตริง Base64 "ก่อน" decode ด้วย (ดู `Base64Image`)
     */
    'signatures' => [
        'mime_types' => ['image/jpeg', 'image/png'],
        'extensions' => ['jpg', 'jpeg', 'png'],
        'max_size_kb' => 1024,
    ],

    /*
     * รูปประจำตัวบุคลากร (SEC-028)
     *
     * เดิมช่อง `profile_photo` ไม่มีกฎ validate ใด ๆ เลยทั้งใน `ProfileController`
     * และ `Admin\UserController` — อัปโหลดไฟล์ชนิดใดก็ได้ ขนาดเท่าใดก็ได้
     */
    'profile_photos' => [
        'mime_types' => ['image/jpeg', 'image/png'],
        'extensions' => ['jpg', 'jpeg', 'png'],
        'max_size_kb' => 2048,
    ],

    /*
     * ภาพถ่ายตอนลงเวลา — รับจากกล้องบนอุปกรณ์ จึงยอม WebP ด้วย
     */
    'attendance_photos' => [
        'mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'max_size_kb' => 5120,
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
    |--------------------------------------------------------------------------
    | ด่านตรวจไวรัส (SEC-GAP-02 · INT-04)
    |--------------------------------------------------------------------------
    |
    | allowlist ด้านบนกันได้แค่ "ชนิดไฟล์" กับ "ขนาด" — กันเนื้อหาที่เป็นอันตราย
    | ในไฟล์ชนิดที่อนุญาตไม่ได้ (เช่น PDF ที่ฝัง payload) ด่านนี้จึงเป็นชั้นที่สอง
    |
    | `enabled` ปิดได้เพื่อการพัฒนาในเครื่องที่ไม่ได้รัน clamav แต่
    | `ProductionEnvironmentGuard` จะไม่ยอมให้ระบบขึ้น production ถ้าปิดไว้
    |
    */
    'malware_scan' => [
        'enabled' => (bool) env('UPLOAD_MALWARE_SCAN', true),
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
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
