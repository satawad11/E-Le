<?php

namespace App\Services\DocumentEngine;

/**
 * ฟอนต์ไทยของเอกสารที่วางฟิลด์บน PDF
 *
 * FPDF อ่านค่า `FPDF_FONTPATH` ตอนสร้างอ็อบเจกต์เท่านั้น การประกาศจึงต้องเกิดก่อน `new Fpdi`
 * เสมอ และทุกที่ที่สร้างเอกสารต้องใช้ทางเดียวกัน มิฉะนั้นจะพลาดเป็นฟอนต์แกนของ FPDF
 * ที่หาไฟล์ไม่เจอแล้วล้มทั้งการพิมพ์ (เคยเกิดกับช่องติ๊กมาแล้ว)
 */
class OverlayFonts
{
    public const REGULAR = 'THSarabunNew';

    public const BOLD = 'THSarabunNewB';

    /** @throws \RuntimeException เมื่อยังไม่ได้ติดตั้งฟอนต์ */
    public static function boot(): void
    {
        $dir = storage_path('fonts/fpdf');

        if (! is_file($dir.'/'.self::REGULAR.'.json') && ! is_file($dir.'/'.self::REGULAR.'.php')) {
            throw new \RuntimeException('ยังไม่ได้ติดตั้งฟอนต์ไทยของ Document Engine — รัน `php artisan document:install-fonts` ก่อน');
        }

        if (! defined('FPDF_FONTPATH')) {
            define('FPDF_FONTPATH', $dir.DIRECTORY_SEPARATOR);
        }
    }

    /** ลงทะเบียนทั้งแบบปกติและแบบขีดเส้นใต้ เพราะ FPDF ถือว่าเป็นคนละสไตล์ของฟอนต์เดียวกัน */
    public static function register(OverlayPdf $pdf): void
    {
        foreach ([self::REGULAR, self::BOLD] as $family) {
            $pdf->AddFont($family, '', $family.'.json');
            $pdf->AddFont($family, 'U', $family.'.json');
        }
    }
}
