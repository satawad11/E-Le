<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Setting;

/**
 * เครื่องมือสร้างชื่อไฟล์หนังสือราชการอิเล็กทรอนิกส์ตามระเบียบสำนักนายกรัฐมนตรีฯ (ฉบับที่ ๔) พ.ศ. ๒๕๖๔
 *
 * อ้างอิงภาคผนวก ๗ ข้อ ๔.๓:
 *   ๔.๓.๑ ปีพุทธศักราช
 *   ๔.๓.๒ รหัสตัวอักษรโรมันประจำส่วนราชการ (กระทรวงศึกษาธิการ = MOE)
 *   ๔.๓.๓ เลขประจำของส่วนราชการเจ้าของเรื่อง
 *   ๔.๓.๔ เลขที่ของหนังสือตามทะเบียนหนังสือส่ง
 *   ๔.๓.๕ กรณีมีหลายไฟล์ ให้ไฟล์สิ่งที่จะส่งไปด้วยใช้ชื่อไฟล์เดียวกับหนังสือ แล้วตามด้วยตัวเลขตั้งแต่ 1 เป็นต้นไป
 *
 * กฎชื่อไฟล์: ใช้เลขอารบิก และใช้เครื่องหมายขีดล่าง (_) แทนการเว้นวรรคหรือเครื่องหมายสงวน เช่น (/) (.) ($)
 * ตัวอย่าง: 2564_OPM0913_56.pdf หรือ 2567_MOE06001_125.pdf (สิ่งที่ส่งมาด้วย: 2567_MOE06001_125_1.pdf)
 */
class OfficialSarabanFilenameService
{
    public const DEFAULT_ROMAN_CODE = 'MOE'; // กระทรวงศึกษาธิการ / สอศ. อาชีวศึกษา

    /**
     * สร้างชื่อไฟล์มาตรฐานสำหรับหนังสือหรือสิ่งที่ส่งมาด้วย
     */
    public function generate(Document $document, int $attachmentIndex = 0, ?string $customRomanCode = null): string
    {
        $year = $this->resolveBuddhistYear($document);
        $romanCode = $this->resolveRomanCode($customRomanCode);
        $unitCode = $this->resolveUnitCode($document);
        $runningNumber = $this->resolveRunningNumber($document);

        $romanUnit = $this->sanitizeSegment("{$romanCode}{$unitCode}");
        $base = "{$year}_{$romanUnit}_{$runningNumber}";

        if ($attachmentIndex > 0) {
            $base .= "_{$attachmentIndex}";
        }

        return "{$base}.pdf";
    }

    /**
     * ดึงปี พ.ศ. จากเลขหนังสือ หรือวันที่ออกหนังสือ หรือเวลาปัจจุบัน
     */
    public function resolveBuddhistYear(Document $document): int
    {
        if ($document->documentNumber?->buddhist_year) {
            return (int) $document->documentNumber->buddhist_year;
        }

        if ($document->issued_at) {
            return $document->issued_at->year + 543;
        }

        return (int) (date('Y') + 543);
    }

    /**
     * รหัสตัวอักษรโรมันประจำส่วนราชการ
     */
    public function resolveRomanCode(?string $override = null): string
    {
        if (! blank($override)) {
            return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $override));
        }

        $fromSetting = Setting::where('key', 'saraban_ministry_roman_code')->value('value');
        if (! blank($fromSetting)) {
            return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $fromSetting));
        }

        return self::DEFAULT_ROMAN_CODE;
    }

    /**
     * รหัสประจำส่วนราชการเจ้าของเรื่อง (เช่น รหัสสถานศึกษา / รหัสฝ่าย)
     */
    public function resolveUnitCode(Document $document): string
    {
        // 1. จากรหัสหน่วยงานเจ้าของเรื่อง
        if (! empty($document->ownerOrgUnit?->code)) {
            $cleaned = preg_replace('/[^A-Za-z0-9]/', '', $document->ownerOrgUnit->code);
            if (! empty($cleaned)) {
                return $cleaned;
            }
        }

        // 2. จากการตั้งค่ารหัสสถานศึกษาในระบบ
        $institutionCode = Setting::where('key', 'institution_code')->value('value');
        if (! blank($institutionCode)) {
            $cleaned = preg_replace('/[^A-Za-z0-9]/', '', $institutionCode);
            if (! empty($cleaned)) {
                return $cleaned;
            }
        }

        return '01';
    }

    /**
     * เลขที่ของหนังสือตามทะเบียนหนังสือส่ง
     */
    public function resolveRunningNumber(Document $document): string
    {
        if ($document->documentNumber?->running_number) {
            return (string) $document->documentNumber->running_number;
        }

        if ($document->incomingRegistration?->registerNumber?->running_number) {
            return (string) $document->incomingRegistration->registerNumber->running_number;
        }

        return (string) $document->id;
    }

    /**
     * ทำความสะอาดข้อความตามกฎข้อ ๔.๓ (ห้ามมี reserved characters เช่น / . $ หรือเว้นวรรค)
     */
    public function sanitizeSegment(string $text): string
    {
        // แปลงเว้นวรรค และเครื่องหมายพิเศษเป็น underscore
        $cleaned = preg_replace('/[\s\/\.\$\-\\\\]+/', '_', trim($text));
        $cleaned = preg_replace('/[^A-Za-z0-9_]/', '', $cleaned);

        return trim($cleaned, '_');
    }
}
