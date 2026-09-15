<?php

namespace App\Services;

use App\Models\User;

/**
 * บริการ TOTP (Time-based One-Time Password) ตามมาตรฐาน RFC 6238 (SoT §24 · ปิด RISK-29)
 *
 * รองรับการยืนยันตัวตนซ้ำ (Step-up Authentication) สำหรับการลงนามคำสั่งและหนังสือราชการสำคัญ
 * ทำงานแบบ Zero Schema Change โดยสร้าง Base32 Secret ประจำตัวผู้ใช้ด้วย HMAC ผูกกับ APP_KEY และ User UUID
 */
class TotpService
{
    /**
     * คำนวณรหัส OTP 6 หลักสำหรับ Secret ที่กำหนด ณ เวลา timestamp
     */
    public function generateCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $timeStep = 30;
        $counter = (int) floor($timestamp / $timeStep);
        $binaryCounter = pack('N*', 0).pack('N*', $counter);

        $decodedSecret = $this->base32Decode($secret);
        $hash = hash_hmac('sha1', $binaryCounter, $decodedSecret, true);

        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncatedHash = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $code = $truncatedHash % 1000000;

        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * ตรวจสอบความถูกต้องของรหัส TOTP 6 หลัก (ครอบคลุมช่วงเวลา +- 1 หน้าต่าง = +- 30 วินาที)
     */
    public function verify(string $secret, string $code, int $discrepancy = 1): bool
    {
        $cleanCode = trim($code);
        if (strlen($cleanCode) !== 6 || ! ctype_digit($cleanCode)) {
            return false;
        }

        $currentTime = time();
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calcTime = $currentTime + ($i * 30);
            if (hash_equals($this->generateCode($secret, $calcTime), $cleanCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ดึงหรือสร้าง Base32 Secret ที่ผูกกับบัญชีผู้ใช้
     */
    public function secretForUser(User $user): string
    {
        $hash = hash_hmac('sha256', (string) ($user->uuid ?? $user->id), (string) config('app.key'));
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $chars[hexdec(substr($hash, $i * 2, 2)) % 32];
        }

        return $secret;
    }

    private function base32Decode(string $b32): string
    {
        $b32 = strtoupper(trim($b32));
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        for ($i = 0; $i < strlen($b32); $i++) {
            $pos = strpos($chars, $b32[$i]);
            if ($pos === false) {
                continue;
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        for ($i = 0; $i + 8 <= strlen($binary); $i += 8) {
            $bytes .= chr(bindec(substr($binary, $i, 8)));
        }

        return $bytes;
    }
}
