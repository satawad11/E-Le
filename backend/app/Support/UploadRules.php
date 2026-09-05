<?php

namespace App\Support;

use App\Rules\FreeOfMalware;
use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<int,ValidationRule|string>
     */
    public static function attachment(?int $maxSizeKb = null): array
    {
        return self::rulesFor('attachments', $maxSizeKb);
    }

    /**
     * รูปภาพของระบบ (โลโก้ / ตราสัญลักษณ์)
     *
     * @return array<int,ValidationRule|string>
     */
    public static function image(?int $maxSizeKb = null): array
    {
        return array_merge(['image'], self::rulesFor('images', $maxSizeKb));
    }

    /**
     * แม่แบบเอกสารของ Document Engine
     *
     * @return array<int,ValidationRule|string>
     */
    public static function documentTemplate(?int $maxSizeKb = null): array
    {
        return self::rulesFor('document_templates', $maxSizeKb);
    }

    /**
     * ไฟล์แนบของหนังสือในงานสารบรรณ (INT-04)
     *
     * @return array<int,ValidationRule|string>
     */
    public static function sarabanAttachment(?int $maxSizeKb = null): array
    {
        return self::rulesFor('saraban_attachments', $maxSizeKb);
    }

    /**
     * ไฟล์ลายเซ็นอิเล็กทรอนิกส์ที่อัปโหลดเข้ามา (SEC-028)
     *
     * @return array<int,ValidationRule|string>
     */
    public static function signatureFile(?int $maxSizeKb = null): array
    {
        return array_merge(['image'], self::rulesFor('signatures', $maxSizeKb));
    }

    /**
     * รูปประจำตัวบุคลากร (SEC-028)
     *
     * @return array<int,ValidationRule|string>
     */
    public static function profilePhoto(?int $maxSizeKb = null): array
    {
        return array_merge(['image'], self::rulesFor('profile_photos', $maxSizeKb));
    }

    /**
     * ชนิดไฟล์ที่อนุญาตของชุดใดชุดหนึ่ง (ตรวจจากเนื้อไฟล์จริง)
     *
     * @return array<int,string>
     */
    public static function allowedMimeTypes(string $profile): array
    {
        return self::profileConfig($profile)['mime_types'];
    }

    /** เพดานขนาดไฟล์ของชุดนั้นเป็นไบต์ */
    public static function maxSizeBytes(string $profile): int
    {
        return self::profileConfig($profile)['max_size_kb'] * 1024;
    }

    /**
     * นามสกุลที่ควรใช้เก็บไฟล์ตามชนิดจริงของเนื้อไฟล์
     *
     * ไม่เชื่อชื่อไฟล์หรือ MIME ที่ client ส่งมา
     */
    public static function extensionForMimeType(string $mimeType): ?string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => null,
        };
    }

    /**
     * @return array{mime_types: array<int,string>, extensions: array<int,string>, max_size_kb: int}
     */
    private static function profileConfig(string $profile): array
    {
        $config = config("uploads.{$profile}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("ไม่พบ allowlist ของไฟล์อัปโหลดชุด `{$profile}`");
        }

        return $config;
    }

    /**
     * @return array<int,ValidationRule|string>
     */
    private static function rulesFor(string $profile, ?int $maxSizeKb): array
    {
        $config = self::profileConfig($profile);

        return [
            'file',
            'mimetypes:'.implode(',', $config['mime_types']),
            'extensions:'.implode(',', $config['extensions']),
            'max:'.($maxSizeKb ?? $config['max_size_kb']),
            /*
             * SEC-GAP-02 (INT-04) — ชั้นที่สอง: ตรวจ "เนื้อหา" ไม่ใช่แค่ "ชนิด"
             *
             * ต่อท้ายที่นี่ที่เดียว ทุกจุดที่รับไฟล์จึงได้ด่านตรวจไวรัสทันที
             * โดยไม่ต้องไล่แก้ controller — ตรงกับหลักแหล่งความจริงเดียวของ allowlist
             */
            new FreeOfMalware,
        ];
    }
}
