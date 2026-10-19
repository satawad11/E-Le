<?php

namespace App\Services\Access;

use App\Models\KnownDevice;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * อุปกรณ์ที่รู้จัก (CORE-03 "อุปกรณ์ใหม่ → อีเมลแจ้งเตือน")
 *
 * หน้าเว็บส่ง `device_id` สุ่มที่เก็บในเบราว์เซอร์ · ไม่ส่งมา (เช่น client อื่น) ใช้ user agent แทน
 * ฐานข้อมูลเก็บเฉพาะ hash · "จดจำอุปกรณ์นี้" ขยายเวลาที่ถือว่ารู้จักเป็น 30 วัน — ไม่ข้าม MFA (ADR-R-020)
 */
class DeviceRegistry
{
    public function hash(?string $deviceId, ?string $userAgent): string
    {
        $deviceId = is_string($deviceId) ? trim($deviceId) : '';

        return hash('sha256', $deviceId !== '' ? 'id:'.mb_substr($deviceId, 0, 120) : 'ua:'.(string) $userAgent);
    }

    public function isKnown(User $user, string $hash): bool
    {
        return KnownDevice::where('user_id', $user->id)
            ->where('device_hash', $hash)
            ->where('trusted_until', '>', now())
            ->exists();
    }

    public function remember(User $user, string $hash, bool $remember, ?string $ip, ?string $userAgent): KnownDevice
    {
        $until = $remember
            ? now()->addDays((int) config('auth.devices.remember_days', 30))
            : now()->addHours((int) config('auth.devices.session_hours', 12));

        $device = KnownDevice::firstOrNew(['user_id' => $user->id, 'device_hash' => $hash]);
        $device->forceFill([
            'label' => Str::limit($this->label($userAgent), 117),
            'ip' => $ip,
            'first_seen_at' => $device->first_seen_at ?? now(),
            'last_seen_at' => now(),
            'trusted_until' => $device->trusted_until !== null && $device->trusted_until->greaterThan($until) ? $device->trusted_until : $until,
        ])->save();

        return $device;
    }

    /** ชื่ออุปกรณ์แบบอ่านง่ายจาก user agent (แสดงในหน้าความปลอดภัยบัญชีและอีเมลแจ้งเตือน) */
    public function label(?string $userAgent): string
    {
        $ua = (string) $userAgent;
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'เบราว์เซอร์อื่น',
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'ระบบอื่น',
        };

        return "{$browser} · {$os}";
    }
}
