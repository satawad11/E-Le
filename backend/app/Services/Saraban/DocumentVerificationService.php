<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Str;

/**
 * รหัสตรวจสอบหนังสือและ QR สำหรับพิมพ์ลงเอกสาร (SoT §12 · INT-12)
 *
 * **REUSE รูปแบบของโมดูลการลาทั้งหมด** — ไลบรารี QR ตัวเดียวกัน
 * (`chillerlan/php-qrcode` ที่มีอยู่แล้ว) โทเคนสุ่ม 40 ตัวอักษรแบบเดียวกัน
 * และแนวคิด "ผนึกลายนิ้วมือเนื้อหาตอนออกเอกสาร" แบบเดียวกัน
 *
 * เหตุผลที่ไม่ประดิษฐ์ใหม่: รูปแบบนี้ใช้งานจริงในระบบมาแล้วและมีเทสต์คุมอยู่
 * การทำอีกแบบหนึ่งสำหรับสารบรรณจะได้กลไกตรวจสอบสองชุดที่ต้องดูแลแยกกัน
 */
class DocumentVerificationService
{
    /**
     * ลายนิ้วมือของสาระสำคัญของหนังสือ
     *
     * เลือกเฉพาะฟิลด์ที่ปรากฏบนหน้าเอกสารและมีผลทางกฎหมาย — การเพิ่มฟิลด์
     * เชิงเทคนิคในอนาคตจึงไม่ทำให้หนังสือที่ออกไปแล้วกลายเป็น "ถูกแก้ไข"
     *
     * ผสม `app.key` เข้าไปด้วย เพื่อให้ผู้ที่รู้ข้อมูลบนหน้าเอกสารอย่างเดียว
     * คำนวณค่าที่ถูกต้องขึ้นมาเองไม่ได้
     */
    public function contentHash(Document $document): string
    {
        return hash('sha256', implode('|', [
            $document->id,
            $document->subject,
            $document->direction,
            $document->document_type_id,
            $document->owner_org_unit_id,
            $document->documentNumber?->formatted ?? '',
            $document->issued_at?->toIso8601String() ?? '',
            config('app.key'),
        ]));
    }

    /** ออกโทเคนตรวจสอบให้หนังสือที่ยังไม่มี แล้วคืนค่าโทเคน */
    public function ensureHash(Document $document): string
    {
        if ($document->verification_hash) {
            return $document->verification_hash;
        }

        $document->verification_hash = Str::random(40);
        $document->save();

        return $document->verification_hash;
    }

    /** ผนึกลายนิ้วมือไว้ตอนออกหนังสือ เพื่อให้ตรวจการแก้ไขย้อนหลังได้ */
    public function seal(Document $document): void
    {
        $this->ensureHash($document);
        $document->content_hash = $this->contentHash($document);
        $document->save();
    }

    /** หนังสือฉบับนี้ถูกแก้ข้อมูลหลังออกหนังสือหรือไม่ */
    public function wasAltered(Document $document): bool
    {
        return $document->content_hash !== null
            && ! hash_equals($document->content_hash, $this->contentHash($document));
    }

    /** ชี้ไปหน้าเว็บฝั่ง frontend — ตั้งค่าได้ด้วย FRONTEND_URL เหมือนของใบลา */
    public function verificationUrl(string $hash): string
    {
        return rtrim(env('FRONTEND_URL') ?: config('app.url'), '/').'/verify-saraban/'.$hash;
    }

    public function qrCodeSvg(string $hash): string
    {
        return (new QRCode($this->options()))->render($this->verificationUrl($hash));
    }

    /**
     * เมทริกซ์ของ QR สำหรับวาดเป็นสี่เหลี่ยมลงบน PDF โดยตรง
     *
     * **ไม่แปลงเป็นภาพ** — `SarabanPdfRenderer` วาดแต่ละช่องเป็นสี่เหลี่ยมทึบ
     * จึงได้ QR ที่เป็นเวกเตอร์ คมทุกระดับการขยาย และรักษาข้อบังคับของ SoT §14
     * ที่ว่าไฟล์ผลลัพธ์ต้องไม่ประกอบขึ้นจากภาพแรสเตอร์
     */
    public function matrixFor(string $hash): QRMatrix
    {
        return (new QRCode($this->options()))->getQRMatrix($this->verificationUrl($hash));
    }

    private function options(): QROptions
    {
        return new QROptions([
            'version' => 5,
            'outputInterface' => QRMarkupSVG::class,
            'eccLevel' => EccLevel::M,
            'outputBase64' => false,
            'svgUseFillAttributes' => false,
        ]);
    }
}
