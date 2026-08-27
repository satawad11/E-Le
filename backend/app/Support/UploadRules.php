<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * SEC-023R — สร้างกฎ validate ของไฟล์อัปโหลดจาก allowlist กลาง
 *
 * ทุกจุดที่รับไฟล์ต้องเรียกผ่านที่นี่ ไม่เขียน `mimes:`/`max:` เองในตัว
 * controller อีก เพื่อให้ allowlist มีแหล่งความจริงเดียวที่ `config/uploads.php`
 *
 * กฎที่คืนออกไปมีสองชั้นเสมอ:
 *   `mimetypes:` ตรวจจาก magic bytes ของเนื้อไฟล์ (ปลอมด้วยการเปลี่ยนชื่อไม่ได้)
 *   `extensions:` ตรวจนามสกุลจริง (กันไฟล์ที่เนื้อหาผ่านแต่ลงดิสก์เป็น .php)
 */
final class UploadRules
{
    /**
     * เอกสารแนบของผู้ใช้ (หลักฐานประกอบการลา / ไฟล์แนบใบลา)
     *
     * @return array<int,string>
     */
    public static function attachment(?int $maxSizeKb = null): array
    {
        return self::rulesFor('attachments', $maxSizeKb);
    }

    /**
     * รูปภาพของระบบ (โลโก้ / ตราสัญลักษณ์)
     *
     * @return array<int,string>
     */
    public static function image(?int $maxSizeKb = null): array
    {
        return array_merge(['image'], self::rulesFor('images', $maxSizeKb));
    }

    /**
     * แม่แบบเอกสารของ Document Engine
     *
     * @return array<int,string>
     */
    public static function documentTemplate(?int $maxSizeKb = null): array
    {
        return self::rulesFor('document_templates', $maxSizeKb);
    }

    /**
     * @return array<int,string>
     */
    private static function rulesFor(string $profile, ?int $maxSizeKb): array
    {
        $config = config("uploads.{$profile}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("ไม่พบ allowlist ของไฟล์อัปโหลดชุด `{$profile}`");
        }

        return [
            'file',
            'mimetypes:'.implode(',', $config['mime_types']),
            'extensions:'.implode(',', $config['extensions']),
            'max:'.($maxSizeKb ?? $config['max_size_kb']),
        ];
    }
}
