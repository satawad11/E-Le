<?php

namespace App\Services\Access;

use App\Models\MfaCredential;
use App\Models\User;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238) และรหัสสำรอง — ADR-R-019 ใช้ pragmarx/google2fa
 *
 * - secret และรหัสสำรองเข้ารหัสที่ฐานข้อมูล (cast `encrypted`) · รหัสสำรองเก็บเป็น hash ใช้ได้ครั้งเดียว
 * - รหัส TOTP เดิม (timestep เดิมหรือเก่ากว่า) ใช้ซ้ำไม่ได้ · ยอมคลาดเวลา ±1 ช่วง (30 วินาที)
 */
class TotpService
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA;
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('auth.mfa.issuer', 'DMTC'), $user->email, $secret);
    }

    public function qrSvg(string $uri): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'eccLevel' => EccLevel::M,
            'outputBase64' => false,
            'svgUseFillAttributes' => false,
        ]);

        return (new QRCode($options))->render($uri);
    }

    /** รหัสปัจจุบันของ secret (ใช้ในเทสต์และเครื่องมือผู้ดูแลเท่านั้น) */
    public function currentCode(string $secret): string
    {
        return $this->google2fa->getCurrentOtp($secret);
    }

    /** ตรวจรหัส 6 หลักและบันทึก timestep ที่ใช้แล้ว */
    public function verify(MfaCredential $credential, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }

        // ส่ง timestep เดิม (หรือ 0) เสมอ ไลบรารีจึงคืน timestep ที่ตรงจริงแทน true — ใช้กันรหัสซ้ำได้แม่นยำ
        $timestep = $this->google2fa->verifyKeyNewer($credential->secret, $code, $credential->last_timestep ?? 0, 1);
        if (! is_int($timestep)) {
            return false;
        }

        $credential->forceFill(['last_timestep' => $timestep, 'last_used_at' => now()])->save();

        return true;
    }

    /**
     * สร้างรหัสสำรองชุดใหม่ (แทนชุดเดิมทั้งหมด) — คืนรหัสจริงครั้งเดียว เก็บเฉพาะ hash
     *
     * @return array<int, string>
     */
    public function issueRecoveryCodes(MfaCredential $credential): array
    {
        $codes = [];
        for ($i = 0; $i < (int) config('auth.mfa.recovery_codes', 8); $i++) {
            $codes[] = Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5));
        }

        $credential->forceFill(['recovery_codes' => array_map(fn (string $c) => $this->hashRecovery($c), $codes)])->save();

        return $codes;
    }

    public function useRecoveryCode(MfaCredential $credential, string $code): bool
    {
        $hash = $this->hashRecovery($code);
        $remaining = $credential->recovery_codes ?? [];

        foreach ($remaining as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($remaining[$i]);
                $credential->forceFill(['recovery_codes' => array_values($remaining), 'last_used_at' => now()])->save();

                return true;
            }
        }

        return false;
    }

    public function remainingRecoveryCodes(MfaCredential $credential): int
    {
        return count($credential->recovery_codes ?? []);
    }

    private function hashRecovery(string $code): string
    {
        return hash_hmac('sha256', Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''), (string) config('app.key'));
    }
}
