<?php

namespace App\Services;

use App\Models\Leave;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Str;

/**
 * รหัสตรวจสอบใบลาและ QR สำหรับพิมพ์ลงเอกสาร
 * ใช้ไลบรารีตัวเดียวกับที่โมดูลสารบรรณใช้ตรวจสอบหนังสือ
 */
class LeaveVerificationService
{
    /** ลายนิ้วมือของเนื้อหาใบลา ใช้ตรวจว่าข้อมูลถูกแก้หลังอนุมัติหรือไม่ */
    public function contentHash(Leave $leave): string
    {
        return hash('sha256', implode('|', [
            $leave->id,
            $leave->user_id,
            $leave->leave_type_id,
            $leave->document_number,
            $leave->start_date?->toDateString(),
            $leave->end_date?->toDateString(),
            (float) $leave->total_days,
            config('app.key'),
        ]));
    }

    /** ออกโทเคนตรวจสอบให้ใบลาที่ยังไม่มี แล้วคืนค่าโทเคน */
    public function ensureHash(Leave $leave): string
    {
        if ($leave->verification_hash) {
            return $leave->verification_hash;
        }

        $leave->verification_hash = Str::random(40);
        $leave->save();

        return $leave->verification_hash;
    }

    /** ผนึกลายนิ้วมือเนื้อหาไว้ตอนอนุมัติ เพื่อให้ตรวจการแก้ไขย้อนหลังได้ */
    public function seal(Leave $leave): void
    {
        $this->ensureHash($leave);
        $leave->content_hash = $this->contentHash($leave);
        $leave->save();
    }

    /** ชี้ไปหน้าเว็บฝั่ง frontend ตั้งค่าได้ด้วย FRONTEND_URL ไม่งั้นใช้ APP_URL */
    public function verificationUrl(string $hash): string
    {
        return rtrim(env('FRONTEND_URL') ?: config('app.url'), '/').'/verify-leave/'.$hash;
    }

    /**
     * php-qrcode v6 ใช้ outputInterface แทนค่าคงที่ OUTPUT_* แบบเดิม
     * และ render() คืนค่าเป็น data URI มาให้เลย ถ้าอยากได้ SVG ดิบต้องปิด outputBase64
     */
    private function render(string $hash, bool $asDataUri): string
    {
        $options = new QROptions([
            'version' => 5,
            'outputInterface' => QRMarkupSVG::class,
            'eccLevel' => EccLevel::M,
            'outputBase64' => $asDataUri,
            'svgUseFillAttributes' => false,
        ]);

        return (new QRCode($options))->render($this->verificationUrl($hash));
    }

    public function qrCodeSvg(string $hash): string
    {
        return $this->render($hash, false);
    }

    /** QR แบบ data URI สำหรับฝังใน PDF (dompdf โหลด URL ภายนอกไม่ได้) */
    public function qrCodeDataUri(string $hash): string
    {
        return $this->render($hash, true);
    }
}
