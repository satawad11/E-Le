<?php

namespace App\Services\Saraban;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * ไฟล์แนบของงานสารบัญเก็บบน disk ส่วนตัว (storage/app/private) เท่านั้น
 * เพื่อไม่ให้หนังสือชั้นความลับถูกเปิดผ่าน URL /storage/... โดยไม่ผ่านการตรวจสิทธิ์
 *
 * ไฟล์เดิมที่เคยถูกเก็บบน disk `public` ยังอ่านได้ผ่าน resolveDisk()
 */
class AttachmentStorage
{
    public const DISK = 'local';

    public const DIRECTORY = 'saraban/attachments';

    public function putUploadedFile(UploadedFile $file): string
    {
        return $file->store(self::DIRECTORY, self::DISK);
    }

    public function putContents(string $filename, string $contents): string
    {
        $path = self::DIRECTORY . '/' . $filename;
        Storage::disk(self::DISK)->put($path, $contents);

        return $path;
    }

    /**
     * หา disk ที่ไฟล์อยู่จริง (รองรับไฟล์เก่าบน disk public)
     */
    public function resolveDisk(string $path): ?string
    {
        foreach ([self::DISK, 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    public function delete(string $path): void
    {
        if ($disk = $this->resolveDisk($path)) {
            Storage::disk($disk)->delete($path);
        }
    }
}
