<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * แปลงค่า path ที่เก็บในฐานข้อมูล ("/storage/signatures/abc.png") เป็นตำแหน่งจริงบน disk
 *
 * เดิมโค้ดใช้ public_path() ต่อกับค่าที่เก็บไว้ ซึ่งพึ่ง symlink public/storage
 * ที่ทำให้ไฟล์ทั้งหมดเข้าถึงได้จากอินเทอร์เน็ตโดยไม่ต้องยืนยันตัวตน (SEC-001)
 *
 * ไฟล์ย้ายไปอยู่บน disk 'private_uploads' แล้ว แต่ค่าที่เก็บใน DB คงรูปแบบเดิมไว้
 * เพื่อไม่ต้อง backfill ข้อมูล — คลาสนี้คือจุดเดียวที่รู้วิธีแปลง
 */
class StoredFile
{
    public const DISK = 'private_uploads';

    /**
     * ตัด prefix "/storage/" ออกให้เหลือ path เทียบกับ disk
     * คืน null ถ้าค่าว่างหรือมีรูปแบบที่ไม่ปลอดภัย
     */
    public static function relative(?string $stored): ?string
    {
        if (blank($stored)) {
            return null;
        }

        $path = ltrim(Str::after('/'.ltrim($stored, '/'), '/storage/'), '/');

        // กัน path traversal และ null byte
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            return null;
        }

        return $path;
    }

    /** ตำแหน่งไฟล์จริงบนดิสก์ คืน null ถ้าไม่มีไฟล์ */
    public static function path(?string $stored): ?string
    {
        $relative = self::relative($stored);

        if ($relative === null) {
            return null;
        }

        $disk = Storage::disk(self::DISK);

        return $disk->exists($relative) ? $disk->path($relative) : null;
    }

    public static function exists(?string $stored): bool
    {
        return self::path($stored) !== null;
    }
}
