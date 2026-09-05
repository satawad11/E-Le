<?php

namespace App\Services\Files;

use App\Support\UploadRules;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SEC-028 — ที่เดียวที่เขียนภาพของผู้ใช้ลงดิสก์
 *
 * ก่อนแก้ ทุก controller เขียนไฟล์เอง แต่ละที่ตัดสินใจคนละแบบ:
 *
 *   - `ProfileController` ตั้งชื่อไฟล์จาก `uniqid()` ซึ่งเป็นเวลาปัจจุบันแบบเดา
 *     ลำดับได้ และเชื่อ "ชนิด" จากส่วนหัว data URI ที่ client เขียนเอง
 *     ส่งหัวเป็น `data:image/php;base64,` ก็ได้ไฟล์ `.php` ลงดิสก์
 *   - `profile_photo` ไม่มีกฎ validate ใด ๆ เลยทั้งใน `ProfileController`
 *     และ `Admin\UserController`
 *   - `LeaveCancellationController` / `LeaveRequestController` ตรวจนามสกุลจาก
 *     data URI แต่ไม่ตรวจเนื้อไฟล์และไม่จำกัดขนาด
 *
 * บริการนี้บังคับกฎเดียวกันให้ทุกจุด:
 *   1. ชนิดไฟล์ตัดสินจาก magic bytes ของเนื้อไฟล์เท่านั้น
 *   2. นามสกุลที่เก็บมาจากชนิดจริง ไม่ใช่จากชื่อไฟล์หรือ data URI
 *   3. ชื่อไฟล์เป็น UUID สุ่ม คาดเดาไม่ได้
 *   4. เก็บบน `private_uploads` เสมอ — เข้าถึงได้เฉพาะผ่านเส้นทางที่ตรวจสิทธิ์
 *   5. ขนาดถูกจำกัดตาม allowlist กลางที่ `config/uploads.php`
 */
class ImageUploadService
{
    /**
     * เก็บภาพจากไฟล์ที่อัปโหลดเข้ามา
     *
     * @param  string  $profile  ชุด allowlist ใน `config/uploads.php`
     * @param  string  $directory  โฟลเดอร์ปลายทางบน private disk
     * @return string เส้นทางที่เก็บในฐานข้อมูล (`/storage/...`)
     */
    public function storeUploadedFile(UploadedFile $file, string $profile, string $directory, string $attribute = 'file'): string
    {
        $binary = file_get_contents($file->getRealPath());

        if ($binary === false) {
            throw ValidationException::withMessages([$attribute => 'อ่านไฟล์ที่อัปโหลดไม่สำเร็จ']);
        }

        return $this->put($binary, $profile, $directory, $attribute);
    }

    /**
     * เก็บภาพที่ส่งมาเป็น data URI (ลายเซ็นที่วาดสดบนหน้าจอ)
     *
     * คืน `null` เมื่อไม่ได้ส่งอะไรมา เพื่อให้ผู้เรียกใช้ค่าเดิมต่อได้
     */
    public function storeBase64(?string $dataUrl, string $profile, string $directory, string $attribute = 'signature_base64'): ?string
    {
        if ($dataUrl === null || trim($dataUrl) === '') {
            return null;
        }

        $maxBytes = UploadRules::maxSizeBytes($profile);
        $maxKb = (int) round($maxBytes / 1024);

        // ปฏิเสธตั้งแต่ก่อน decode ไม่ต้องกางไฟล์ใหญ่ขึ้นหน่วยความจำ
        if (strlen($dataUrl) > (int) ceil($maxBytes * 4 / 3) + 128) {
            throw ValidationException::withMessages([$attribute => "ไฟล์ภาพต้องมีขนาดไม่เกิน {$maxKb} กิโลไบต์"]);
        }

        if (preg_match('#^data:[^;,]+;base64,(.+)$#s', $dataUrl, $matches) !== 1) {
            throw ValidationException::withMessages([$attribute => 'รูปแบบไฟล์ภาพไม่ถูกต้อง']);
        }

        $binary = base64_decode($matches[1], true);

        if ($binary === false || $binary === '') {
            throw ValidationException::withMessages([$attribute => 'ข้อมูลภาพไม่ถูกต้องหรือเสียหาย']);
        }

        return $this->put($binary, $profile, $directory, $attribute);
    }

    /**
     * ลบไฟล์ที่เพิ่งเขียนไป — ใช้เมื่อ transaction หลังจากนั้นล้มเหลว
     *
     * ปลอดภัยที่จะเรียกซ้ำ และเงียบเมื่อไม่พบไฟล์
     */
    public function discard(?string $storedPath): void
    {
        if ($storedPath === null || ! str_starts_with($storedPath, '/storage/')) {
            return;
        }

        Storage::disk('private_uploads')->delete(substr($storedPath, strlen('/storage/')));
    }

    private function put(string $binary, string $profile, string $directory, string $attribute): string
    {
        $maxBytes = UploadRules::maxSizeBytes($profile);

        if (strlen($binary) > $maxBytes) {
            $maxKb = (int) round($maxBytes / 1024);

            throw ValidationException::withMessages([$attribute => "ไฟล์ภาพต้องมีขนาดไม่เกิน {$maxKb} กิโลไบต์"]);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($binary);
        $allowed = UploadRules::allowedMimeTypes($profile);

        if (! in_array($mime, $allowed, true)) {
            $names = implode(', ', array_map(
                fn (string $type) => strtoupper((string) UploadRules::extensionForMimeType($type)),
                $allowed
            ));

            throw ValidationException::withMessages([$attribute => "อนุญาตเฉพาะไฟล์ภาพชนิด {$names} เท่านั้น"]);
        }

        // นามสกุลมาจากชนิดจริงของเนื้อไฟล์ ไม่ใช่จากสิ่งที่ client บอก
        $extension = UploadRules::extensionForMimeType($mime);
        $relative = trim($directory, '/').'/'.Str::uuid().'.'.$extension;

        Storage::disk('private_uploads')->put($relative, $binary);

        return '/storage/'.$relative;
    }
}
