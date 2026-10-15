<?php

namespace App\Services\DocumentEngine;

use App\Models\Document\DocumentFieldMapping;
use App\Models\DocumentTemplateVersion;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

/**
 * เรนเดอร์เอกสารด้วยการวางฟิลด์ลงบนไฟล์ PDF ต้นฉบับตามพิกัดที่ผู้ดูแลกำหนด
 *
 * ต้นฉบับคือแบบฟอร์มราชการจริง (สแกนหรือไฟล์ทางการ) ระบบไม่วาดเส้นตารางเอง
 * จึงได้เอกสารที่ "ตรงกับต้นฉบับ" โดยไม่ต้องไล่จัด CSS ให้เหมือน
 *
 * พิกัดที่เก็บในฐานข้อมูลเป็น PDF point วัดจากมุมซ้ายบนของหน้า ซึ่งตรงกับมุมมอง
 * ที่ผู้ดูแลเห็นตอนลากฟิลด์บนหน้าจอ การแปลงหน่วยรวมอยู่ที่ `CoordinateConverter`
 */
class PdfOverlayRenderer
{
    /** ฟอนต์ที่ลงทะเบียนโดย `php artisan document:install-fonts` */
    private const FONT_REGULAR = 'THSarabunNew';

    private const FONT_BOLD = 'THSarabunNewB';

    /**
     * @param  array<string,mixed>  $values  ค่าของฟิลด์ (key = field_code)
     * @param  array{x_offset?: float, y_offset?: float, scale?: float}  $calibration
     * @return string เนื้อไฟล์ PDF
     */
    public function render(DocumentTemplateVersion $version, array $values, array $calibration = []): string
    {
        $disk = Storage::disk('private_uploads');

        if (blank($version->pdf_file_path) || ! $disk->exists($version->pdf_file_path)) {
            throw new \RuntimeException('เวอร์ชันนี้ยังไม่มีไฟล์ PDF ต้นฉบับ กรุณาอัปโหลดแบบฟอร์มก่อน');
        }

        $mappings = $version->fieldMappings()->where('is_active', true)->orderBy('page')->get();

        // ต้องกำหนดที่เก็บฟอนต์ก่อนสร้างเอกสาร เพราะ FPDF อ่านค่านี้ตอน constructor เท่านั้น
        $this->bootFontPath();

        $pdf = new Fpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $this->registerFonts($pdf);

        $pageCount = $pdf->setSourceFile($disk->path($version->pdf_file_path));

        for ($page = 1; $page <= $pageCount; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);

            foreach ($mappings->where('page', $page) as $mapping) {
                $this->draw($pdf, $mapping, $values, $calibration);
            }
        }

        return $pdf->Output('S');
    }

    /**
     * ชี้ FPDF ไปยังโฟลเดอร์ฟอนต์ของโครงการ
     *
     * FPDF อ่านค่า `FPDF_FONTPATH` ตอนสร้างอ็อบเจกต์เท่านั้น จึงต้องประกาศก่อน `new Fpdi`
     * มิฉะนั้นมันจะไปหาไฟล์ฟอนต์ในโฟลเดอร์ของ vendor ซึ่งไม่มีฟอนต์ไทยอยู่
     */
    private function bootFontPath(): void
    {
        $dir = storage_path('fonts/fpdf');

        if (! is_file($dir.'/'.self::FONT_REGULAR.'.json') && ! is_file($dir.'/'.self::FONT_REGULAR.'.php')) {
            throw new \RuntimeException('ยังไม่ได้ติดตั้งฟอนต์ไทยของ Document Engine — รัน `php artisan document:install-fonts` ก่อน');
        }

        if (! defined('FPDF_FONTPATH')) {
            define('FPDF_FONTPATH', $dir.DIRECTORY_SEPARATOR);
        }
    }

    private function registerFonts(Fpdi $pdf): void
    {
        $pdf->AddFont(self::FONT_REGULAR, '', self::FONT_REGULAR.'.json');
        $pdf->AddFont(self::FONT_BOLD, '', self::FONT_BOLD.'.json');
    }

    /** @param array<string,mixed> $values */
    private function draw(Fpdi $pdf, DocumentFieldMapping $mapping, array $values, array $calibration): void
    {
        [$x, $y] = CoordinateConverter::withCalibration(
            (float) $mapping->x,
            (float) $mapping->y,
            (float) ($calibration['x_offset'] ?? 0),
            (float) ($calibration['y_offset'] ?? 0),
            (float) ($calibration['scale'] ?? 1),
        );

        $raw = $mapping->static_text ?? ($values[$mapping->field_code] ?? null);

        match ($mapping->field_type) {
            'CHECKBOX' => $this->drawCheckbox($pdf, $mapping, $raw, $x, $y),
            'SIGNATURE', 'IMAGE' => $this->drawImage($pdf, $mapping, $raw, $x, $y),
            'MULTILINE_TEXT' => $this->drawMultiline($pdf, $mapping, $raw, $x, $y),
            default => $this->drawText($pdf, $mapping, $raw, $x, $y),
        };
    }

    private function drawText(Fpdi $pdf, DocumentFieldMapping $mapping, mixed $raw, float $x, float $y): void
    {
        $text = $this->text($raw);

        if ($text === '') {
            return;
        }

        $size = (float) $mapping->font_size;
        $pdf->SetFont($this->font($mapping), '', $size);

        // ข้อความยาวเกินช่อง: ย่อขนาดลงจนพอดี (ไม่ต่ำกว่า 6pt) เพื่อไม่ให้ทับช่องข้าง ๆ
        if ($mapping->overflow === 'shrink') {
            while ($size > 6 && $pdf->GetStringWidth($text) > (float) $mapping->width) {
                $size -= 0.5;
                $pdf->SetFont($this->font($mapping), '', $size);
            }
        }

        $pdf->SetXY($x, $y);
        $pdf->Cell((float) $mapping->width, (float) $mapping->height, $text, 0, 0, $this->align($mapping->align));
    }

    private function drawMultiline(Fpdi $pdf, DocumentFieldMapping $mapping, mixed $raw, float $x, float $y): void
    {
        $text = $this->text($raw);

        if ($text === '') {
            return;
        }

        $pdf->SetFont($this->font($mapping), '', (float) $mapping->font_size);
        $pdf->SetXY($x, $y);
        $pdf->MultiCell((float) $mapping->width, (float) $mapping->font_size * 1.2, $text, 0, $this->align($mapping->align));
    }

    private function drawCheckbox(Fpdi $pdf, DocumentFieldMapping $mapping, mixed $raw, float $x, float $y): void
    {
        $checked = filter_var($raw, FILTER_VALIDATE_BOOLEAN)
            || (is_string($raw) && $raw !== '' && $raw !== '0' && ! in_array(mb_strtolower($raw), ['false', 'no'], true) && ! is_numeric($raw))
            || (is_numeric($raw) && (float) $raw > 0);

        if (! $checked) {
            return;
        }

        $mark = (string) ($mapping->options['mark'] ?? '/');

        /*
         * เครื่องหมายเป็นอักขระ ASCII (/ หรือ X) จึงใช้ฟอนต์ไทยตัวหนาที่ติดตั้งไว้แล้ว
         * เดิมใช้ Helvetica ซึ่งไม่มีไฟล์ในโฟลเดอร์ฟอนต์ของ engine — ติ๊กช่องใดก็ตามจะทำให้ออกเอกสารล้ม
         */
        $pdf->SetFont(self::FONT_BOLD, '', (float) $mapping->font_size + 4);
        $pdf->SetXY($x, $y);
        $pdf->Cell((float) $mapping->width, (float) $mapping->height, $mark, 0, 0, 'C');
    }

    private function drawImage(Fpdi $pdf, DocumentFieldMapping $mapping, mixed $raw, float $x, float $y): void
    {
        $path = $this->imagePath(is_string($raw) ? $raw : '');

        if ($path === null) {
            return;
        }

        $pdf->Image($path, $x, $y, (float) $mapping->width, (float) $mapping->height);
    }

    /** แปลง path ลายเซ็น/รูปในระบบให้เป็น path จริงบนดิสก์ (กันการอ่านไฟล์นอกที่เก็บ) */
    private function imagePath(string $stored): ?string
    {
        if ($stored === '') {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', $stored), '/');
        $relative = preg_replace('#^storage/#', '', $relative) ?? $relative;

        if (str_contains($relative, '..')) {
            return null;
        }

        $disk = Storage::disk('private_uploads');

        return $disk->exists($relative) ? $disk->path($relative) : null;
    }

    private function font(DocumentFieldMapping $mapping): string
    {
        return $mapping->font === self::FONT_BOLD ? self::FONT_BOLD : self::FONT_REGULAR;
    }

    private function align(?string $align): string
    {
        return match ($align) {
            'center' => 'C',
            'right' => 'R',
            default => 'L',
        };
    }

    /** FPDF ทำงานบนหน้ารหัส cp874 สำหรับภาษาไทย จึงต้องแปลงจาก UTF-8 ก่อนเขียนเสมอ */
    private function text(mixed $raw): string
    {
        if ($raw === null || is_bool($raw)) {
            return '';
        }

        $text = trim((string) $raw);

        if ($text === '') {
            return '';
        }

        $converted = @iconv('UTF-8', 'CP874//TRANSLIT', $text);

        return $converted === false ? $text : $converted;
    }
}
