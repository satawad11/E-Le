<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * เข้ารหัสข้อมูลอ่อนไหวด้วย AES-256 (APP_KEY ของ Laravel) แบบยอมรับข้อมูลเดิม
 * ที่ยังเป็น plaintext ได้ เพื่อให้ทยอยเข้ารหัสได้โดยไม่ต้อง migrate ข้อมูลทั้งก้อนก่อน
 * (ข้อมูลจะถูกเข้ารหัสเมื่อถูกบันทึกครั้งถัดไป)
 */
class TolerantEncrypted implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            return $value; // ค่าเดิมที่ยังไม่ได้เข้ารหัส
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return Crypt::encryptString($value);
    }
}
