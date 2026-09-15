<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * การยืนยันตัวตนซ้ำก่อนทำสิ่งที่อ่อนไหว (SoT §24 · SEC-GAP-01)
 *
 * **ปัญหาที่แก้:** โทเคนของระบบมีอายุหลายชั่วโมง การมีโทเคนที่ยังไม่หมดอายุ
 * จึงไม่ได้แปลว่า "คนที่กดตอนนี้คือเจ้าของบัญชี" — เครื่องที่เปิดค้างไว้
 * ลงนามหนังสือราชการแทนเจ้าของได้ ซึ่งเป็นความเสียหายที่ย้อนกลับไม่ได้
 *
 * กลไกนี้บันทึกเวลาที่ผู้ใช้ยืนยันตัวตนแข็งครั้งล่าสุดไว้ที่โทเคน แล้วให้
 * `RequireRecentAuthentication` ตรวจว่าอยู่ในช่วงที่ยอมรับได้หรือไม่
 *
 * **เป็นความสามารถของระบบกลาง** ทุกโมดูลใช้ร่วมกันได้ (SoT §4)
 *
 * ปิดได้ด้วย `auth.step_up.enabled` เพื่อให้ย้อนกลับได้ทันทีถ้ามีปัญหา
 * แบบเดียวกับ `USE_NEW_PERMISSION_ENGINE` ของ LEGACY-04 — แต่
 * `ProductionEnvironmentGuard` ไม่ยอมให้ปิดบน production
 */
class StepUpAuthentication
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TotpService $totp = new TotpService,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('auth.step_up.enabled', true);
    }

    /** ช่วงเวลาที่ถือว่า "เพิ่งยืนยันตัวตน" (นาที) */
    public function windowMinutes(): int
    {
        return (int) config('auth.step_up.window_minutes', 10);
    }

    /**
     * ผู้ใช้คนนี้ผ่านการยืนยันตัวตนซ้ำภายในช่วงที่กำหนดหรือยัง
     *
     * ผูกกับ **โทเคนใบที่กำลังใช้** ไม่ใช่กับตัวผู้ใช้ — ยืนยันบนเครื่องหนึ่ง
     * ต้องไม่ทำให้อีกเครื่องหนึ่งที่ล็อกอินค้างไว้ลงนามได้ตามไปด้วย
     */
    public function isRecent(?User $user): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        $confirmedAt = $this->confirmedAtFor($user);

        return $confirmedAt !== null && $confirmedAt->gt(now()->subMinutes($this->windowMinutes()));
    }

    /**
     * เวลายืนยันตัวตนของโทเคนใบปัจจุบัน
     *
     * แปลงผ่าน `Carbon::parse()` เสมอ เพราะ `PersonalAccessToken` ของ Sanctum
     * ไม่ได้ประกาศ cast ให้คอลัมน์ที่เราเพิ่มเข้าไป ค่าที่อ่านได้จึงเป็นสตริง
     * การเรียกเมธอดของ Carbon ตรง ๆ จะพังทันที
     */
    private function confirmedAtFor(?User $user): ?Carbon
    {
        $value = $this->tokenFor($user)?->last_authenticated_at;

        return blank($value) ? null : Carbon::parse($value);
    }

    /**
     * ยืนยันตัวตนซ้ำด้วยรหัสผ่านหรือ TOTP 6 หลักของผู้ใช้
     *
     * คืน `false` เมื่อการยืนยันไม่ถูกต้อง — ผู้เรียกเป็นผู้ตัดสินใจเรื่องการจำกัด
     * จำนวนครั้ง (ทำที่ route ด้วย throttle เดิมของระบบ)
     */
    public function confirm(User $user, ?string $password = null, ?string $totpCode = null): bool
    {
        if ($totpCode !== null && trim($totpCode) !== '') {
            return $this->confirmWithTotp($user, $totpCode);
        }

        if ($password !== null && trim($password) !== '') {
            return $this->confirmWithPassword($user, $password);
        }

        return false;
    }

    /**
     * ยืนยันตัวตนซ้ำด้วยรหัสผ่าน
     */
    public function confirmWithPassword(User $user, string $password): bool
    {
        if (! Hash::check($password, $user->password)) {
            $this->audit->log('auth.step_up.failed', $user, $user->id, null, [
                'reason' => 'invalid_password',
                'method' => 'password',
            ], null, $user->id);

            return false;
        }

        $this->markConfirmed($user);

        $this->audit->log('auth.step_up.confirmed', $user, $user->id, null, [
            'window_minutes' => $this->windowMinutes(),
            'method' => 'password',
        ], null, $user->id);

        return true;
    }

    /**
     * ผู้ใช้คนนี้ลงทะเบียนแอป TOTP และยืนยันสำเร็จแล้วหรือยัง
     *
     * ความลับ TOTP คำนวณจาก APP_KEY กับ UUID ของผู้ใช้ จึง "มีอยู่" เสมอแม้ไม่เคย
     * ตั้งค่าแอป — ถ้าไม่ตรวจธงนี้ ผู้ที่ขโมยโทเคนไปจะขอความลับแล้วคำนวณรหัสเอง
     * เพื่อผ่านด่านยืนยันตัวตนซ้ำได้โดยไม่ต้องรู้รหัสผ่าน
     */
    public function totpEnrolled(?User $user): bool
    {
        return $user !== null && filled($user->totp_confirmed_at);
    }

    /**
     * ปิดการลงทะเบียนแอป TOTP — เรียกได้หลังผู้ใช้ยืนยันรหัสจากแอปได้จริงแล้ว
     */
    public function markTotpEnrolled(User $user): void
    {
        $user->forceFill(['totp_confirmed_at' => now()])->save();
    }

    /**
     * รหัส TOTP ของผู้ใช้คนนี้ถูกต้องหรือไม่ (ไม่เปิดช่วงยืนยันตัวตน)
     *
     * ใช้ตอนลงทะเบียนแอป ซึ่งยังไม่ควรถือเป็นการยืนยันตัวตนซ้ำในตัวเอง
     */
    public function totpCodeMatches(User $user, string $totpCode): bool
    {
        return $this->totp->verify($this->totp->secretForUser($user), $totpCode);
    }

    /**
     * ยืนยันตัวตนซ้ำด้วยรหัส TOTP (RFC 6238)
     *
     * รับเฉพาะผู้ที่ลงทะเบียนแอปและยืนยันไว้แล้วเท่านั้น
     */
    public function confirmWithTotp(User $user, string $totpCode): bool
    {
        if (! $this->totpEnrolled($user)) {
            $this->audit->log('auth.step_up.failed', $user, $user->id, null, [
                'reason' => 'totp_not_enrolled',
                'method' => 'totp',
            ], null, $user->id);

            return false;
        }

        $secret = $this->totp->secretForUser($user);

        if (! $this->totp->verify($secret, $totpCode)) {
            $this->audit->log('auth.step_up.failed', $user, $user->id, null, [
                'reason' => 'invalid_totp',
                'method' => 'totp',
            ], null, $user->id);

            return false;
        }

        $this->markConfirmed($user);

        $this->audit->log('auth.step_up.confirmed', $user, $user->id, null, [
            'window_minutes' => $this->windowMinutes(),
            'method' => 'totp',
        ], null, $user->id);

        return true;
    }

    /** บันทึกว่าโทเคนใบปัจจุบันเพิ่งผ่านการยืนยันตัวตน */
    public function markConfirmed(?User $user): void
    {
        $this->tokenFor($user)?->forceFill(['last_authenticated_at' => now()])->save();
    }

    /**
     * หลักฐานอ้างอิงของการยืนยันตัวตนครั้งนี้ — เก็บไว้ในหลักฐานการลงนาม (SoT §14)
     *
     * เก็บเป็น **id ของโทเคน** ไม่ใช่ตัวโทเคน เพื่อให้ตรวจสอบย้อนหลังได้ว่า
     * ลงนามจาก session ใด โดยไม่เก็บความลับไว้ในตารางหลักฐาน
     *
     * @return array{token_id:int|null, authenticated_at:string|null}
     */
    public function assuranceReferenceFor(?User $user): array
    {
        $token = $this->tokenFor($user);

        return [
            'token_id' => $token?->getKey(),
            'authenticated_at' => $this->confirmedAtFor($user)?->toIso8601String(),
        ];
    }

    private function tokenFor(?User $user): ?PersonalAccessToken
    {
        $token = $user?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token : null;
    }
}
