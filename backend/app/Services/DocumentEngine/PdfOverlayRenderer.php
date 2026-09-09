<?php

namespace App\Services\DocumentEngine;

use App\Models\Document\DocumentFieldMapping;
use App\Models\DocumentTemplateVersion;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Storage;

/**
 * เรนเดอร์เอกสารด้วยการวางฟิลด์ลงบนไฟล์ PDF ต้นฉบับตามพิกัดที่ผู้ดูแลกำหนด
 *
 * ต้นฉบับคือแบบฟอร์มราชการจริง (สแกนหรือไฟล์ทางการ) ระบบไม่วาดเส้นตารางเอง
 * จึงได้เอกสารที่ "ตรงกับต้นฉบับ" โดยไม่ต้องไล่จัด CSS ให้เหมือน
 *
 * พิกัดที่เก็บในฐานข้อมูลเป็น PDF point วัดจากมุมซ้ายบนของหน้า ซึ่งตรงกับมุมมอง
 * ที่ผู้ดูแลเห็นตอนลากฟิลด์บนหน้าจอ การแปลงหน่วยรวมอยู่ที่ `CoordinateConverter`
 *
 * รูปแบบค่า (`format`) ตีความที่ `ValueFormatter` ที่เดียว พรีวิวกับไฟล์จริงจึงตรงกันเสมอ
 */
class PdfOverlayRenderer
{
    /** ฟอนต์ที่ลงทะเบียนโดย `php artisan document:install-fonts` */
    private const FONT_REGULAR = OverlayFonts::REGULAR;

    private const FONT_BOLD = OverlayFonts::BOLD;

    public function __construct(private readonly ValueFormatter $formatter) {}

    /**
     * @param  array<string,mixed>  $values  ค่าของฟิลด์ (key = field_code)
     * @param  array{x_offset?: float, y_offset?: float, scale?: float}  $calibration
     * @return string เนื้อไฟล์ PDF
     */
    public function render(DocumentTemplateVersion $version, array $values, array $calibration = [], bool $outline = false): string
    {
        $disk = Storage::disk('private_uploads');

        if (blank($version->pdf_file_path) || ! $disk->exists($version->pdf_file_path)) {
            throw new \RuntimeException('เวอร์ชันนี้ยังไม่มีไฟล์ PDF ต้นฉบับ กรุณาอัปโหลดแบบฟอร์มก่อน');
        }

        $mappings = $version->fieldMappings()->where('is_active', true)->orderBy('page')->get();

        // ต้องกำหนดที่เก็บฟอนต์ก่อนสร้างเอกสาร เพราะ FPDF อ่านค่านี้ตอน constructor เท่านั้น
        OverlayFonts::boot();

        $pdf = new OverlayPdf('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        OverlayFonts::register($pdf);

        $pageCount = $pdf->setSourceFile($disk->path($version->pdf_file_path));

        // ไฟล์รูปชั่วคราวที่สร้างระหว่างเรนเดอร์ (QR) — ลบทิ้งเสมอแม้เกิดข้อผิดพลาดกลางทาง
        $temporary = [];

        try {
            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);

                foreach ($mappings->where('page', $page) as $mapping) {
                    if ($outline) {
                        $this->drawOutline($pdf, $mapping, $calibration);

                        continue;
                    }

                    $this->draw($pdf, $mapping, $values, $calibration, $temporary);
                }
            }

            return $pdf->Output('S');
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $values
     * @param  array<int,string>  $temporary
     */
    private function draw(OverlayPdf $pdf, DocumentFieldMapping $mapping, array $values, array $calibration, array &$temporary): void
    {
        [$x, $y] = CoordinateConverter::withCalibration(
            (float) $mapping->x,
            (float) $mapping->y,
            (float) ($calibration['x_offset'] ?? 0),
            (float) ($calibration['y_offset'] ?? 0),
            (float) ($calibration['scale'] ?? 1),
        );

        if (! $this->isVisible($mapping, $values)) {
            return;
        }

        $raw = $mapping->static_text ?? ($values[$mapping->field_code] ?? null);
        $rotate = (float) ($this->option($mapping, 'rotate') ?? 0);

        if ($rotate !== 0.0) {
            $pdf->rotate($rotate, $x, $y);
        }

        try {
            match ($mapping->field_type) {
                'CHECKBOX' => $this->drawCheckbox($pdf, $mapping, $raw, $x, $y),
                'SIGNATURE', 'IMAGE' => $this->drawImage($pdf, $mapping, $raw, $x, $y),
                'QR_CODE' => $this->drawQrCode($pdf, $mapping, $raw, $x, $y, $temporary),
                'MULTILINE_TEXT' => $this->drawMultiline($pdf, $mapping, $raw, $x, $y),
                default => $this->drawText($pdf, $mapping, $raw, $x, $y),
            };
        } finally {
            // ต้องยกเลิกการหมุนเสมอ ไม่เช่นนั้นฟิลด์ถัดไปบนหน้าเดียวกันจะเอียงตามไปด้วย
            if ($rotate !== 0.0) {
                $pdf->rotate(0, $x, $y);
            }
        }
    }

    /**
     * โหมด "โครงเปล่า" — วาดเฉพาะกรอบของช่องที่วางไว้ ไม่ใส่ค่าใด
     *
     * ใช้ตรวจว่ากรอบตรงกับเส้นบนแบบฟอร์มต้นฉบับหรือไม่ ซึ่งดูจากพรีวิวที่มีข้อความเต็มช่องไม่ออก
     */
    private function drawOutline(OverlayPdf $pdf, DocumentFieldMapping $mapping, array $calibration): void
    {
        [$x, $y] = CoordinateConverter::withCalibration(
            (float) $mapping->x,
            (float) $mapping->y,
            (float) ($calibration['x_offset'] ?? 0),
            (float) ($calibration['y_offset'] ?? 0),
            (float) ($calibration['scale'] ?? 1),
        );

        $pdf->SetDrawColor(200, 0, 0);
        $pdf->SetLineWidth(0.4);
        $pdf->Rect($x, $y, (float) $mapping->width, (float) $mapping->height);

        $pdf->SetFont(self::FONT_REGULAR, '', 7);
        $pdf->SetTextColor(200, 0, 0);
        $pdf->SetXY($x, max(0, $y - 8));
        $pdf->Cell((float) $mapping->width, 8, $this->encode($mapping->field_code), 0, 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * เงื่อนไขแสดง/ซ่อนฟิลด์ (`options.visible_when`)
     *
     * แบบฟอร์มฉบับเดียวมักใช้กับหลายกรณี เช่น บล็อกที่พิมพ์เฉพาะเมื่อเป็นการลาบางประเภท
     * เงื่อนไขจำกัดอยู่ที่การเทียบค่าของฟิลด์อื่นเท่านั้น ไม่มีการรับนิพจน์อิสระจากหน้าจอ
     *
     * @param  array<string,mixed>  $values
     */
    private function isVisible(DocumentFieldMapping $mapping, array $values): bool
    {
        $condition = $this->option($mapping, 'visible_when');

        if (! is_array($condition) || blank($condition['field'] ?? null)) {
            return true;
        }

        $actual = $values[$condition['field']] ?? null;
        $actual = is_scalar($actual) ? trim((string) $actual) : '';
        $expected = trim((string) ($condition['value'] ?? ''));

        return match ($condition['operator'] ?? 'filled') {
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'empty' => $actual === '',
            default => $actual !== '',
        };
    }

    private function drawText(OverlayPdf $pdf, DocumentFieldMapping $mapping, mixed $raw, float $x, float $y): void
    {
        $text = $this->text($raw, $mapping);

        if ($text === '') {
            return;
        }

        $size = (float) $mapping->font_size;
        $spacing = (float) ($this->option($mapping, 'letter_spacing') ?? 0);
        $pdf->SetFont($this->font($mapping), $this->style($mapping), $size);

        // ข้อความยาวเกินช่อง: ย่อขนาดลงจนพอดี (ไม่ต่ำกว่า 6pt) เพื่อไม่ให้ทับช่องข้าง ๆ
        if ($mapping->overflow === 'shrink') {
            $extra = $spacing * max(0, mb_strlen($text) - 1);

            while ($size > 6 && ($pdf->GetStringWidth($text) + $extra) > (float) $mapping->width) {
                $size -= 0.5;
                $pdf->SetFont($this->font($mapping), $this->style($mapping), $size);
            }
        }

        if ($spacing > 0) {
            $pdf->setCharSpacing($spacing);
        }

        $pdf->SetXY($x, $y);
        $pdf->Cell((float) $mapping->width, (float) $mapping->height, $text, 0, 0, $this->align($mapping->align));

        if ($spacing > 0) {
            $pdf->setCharSpacing(0);
        }
    }

    private function drawMultiline(OverlayPdf $pdf, DocumentFieldMapping $mapping, mixed $raw, float $x, float $y): void
    {
        $text = $this->text($raw, $mapping);

        if ($text === '') {
            return;
        }

        $pdf->SetFont($this->font($mapping), $this->style($mapping), (float) $mapping->font_size);
        $pdf->SetXY($x, $y);
        $pdf->MultiCell((float) $mapping->width, (float) $mapping->font_size * 1.2, $text, 0, $this->align($mapping->align));
    }

    private function drawCheckbox(OverlayPdf $pdf, DocumentFieldMapping $mapping, mixed $raw, float $x, float $y): void
    {
        if (! $this->isChecked($mapping, $raw)) {
            return;
        }

        $mark = $this->encode(trim((string) ($this->option($mapping, 'mark') ?? '/')));

        if ($mark === '') {
            return;
        }

        /*
         * ใช้ฟอนต์ไทยของระบบวาดเครื่องหมาย ไม่ใช่ฟอนต์แกนของ FPDF
         *
         * FPDF อ่านนิยามฟอนต์แกน (เช่น helveticab.json) จาก `FPDF_FONTPATH` เช่นกัน
         * ซึ่งโครงการชี้ไปที่โฟลเดอร์ฟอนต์ไทยของตัวเอง การเรียกฟอนต์แกนจึงล้มทั้งการพิมพ์
         * ทันทีที่มีช่องติ๊กถูกติ๊กจริง — THSarabunNew ครอบคลุม ASCII อยู่แล้ว
         * ส่วนอักขระนอก cp874 (เช่น ✓) ถูกแปลงด้วย iconv ตามเส้นทางเดียวกับข้อความอื่น
         */
        $pdf->SetFont($this->font($mapping), '', (float) $mapping->font_size);
        $pdf->SetXY($x, $y);
        $pdf->Cell((float) $mapping->width, (float) $mapping->height, $mark, 0, 0, 'C');
    }

    /**
     * ค่านี้ถือว่า "ติ๊ก" หรือไม่
     *
     * ค่าเริ่มต้นคือค่าที่เป็นจริงตามความหมายทั่วไป และผู้ดูแลกำหนด `checked_values` ได้
     * เพื่อให้ช่องติ๊กผูกกับค่าที่ระบุ เช่น ติ๊กช่อง "ลาป่วย" เมื่อประเภทการลามีค่าตรงกัน
     */
    private function isChecked(DocumentFieldMapping $mapping, mixed $raw): bool
    {
        $expected = $this->option($mapping, 'checked_values');

        if (is_array($expected) && $expected !== []) {
            $value = is_scalar($raw) ? trim((string) $raw) : '';

            return in_array($value, array_map(fn ($item) => trim((string) $item), $expected), true);
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN)
            || (is_string($raw) && $raw !== '' && $raw !== '0' && ! in_array(mb_strtolower($raw), ['false', 'no'], true) && ! is_numeric($raw))
            || (is_numeric($raw) && (float) $raw > 0);
    }

    private function drawImage(OverlayPdf $pdf, DocumentFieldMapping $mapping, mixed $raw, float $x, float $y): void
    {
        $path = $this->imagePath(is_string($raw) ? $raw : '');

        if ($path === null) {
            return;
        }

        $pdf->Image($path, $x, $y, (float) $mapping->width, (float) $mapping->height);
    }

    /**
     * QR ของค่าที่ฟิลด์นั้นถืออยู่ (เช่น ลิงก์ตรวจสอบเอกสาร)
     *
     * ใช้ไลบรารีตัวเดียวกับ QR ตรวจสอบใบลาที่ระบบมีอยู่แล้ว และเขียนเป็นไฟล์ PNG ชั่วคราว
     * เพราะ FPDF อ่านรูปจาก path เท่านั้น ไฟล์ถูกลบทิ้งเสมอเมื่อเรนเดอร์จบ
     *
     * @param  array<int,string>  $temporary
     */
    private function drawQrCode(OverlayPdf $pdf, DocumentFieldMapping $mapping, mixed $raw, float $x, float $y, array &$temporary): void
    {
        $text = is_scalar($raw) ? trim((string) $raw) : '';

        if ($text === '') {
            return;
        }

        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel' => EccLevel::M,
            'outputBase64' => false,
            'scale' => 6,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'docqr').'.png';
        file_put_contents($file, (new QRCode($options))->render($text));
        $temporary[] = $file;

        // QR ต้องเป็นสี่เหลี่ยมจัตุรัส มิฉะนั้นเครื่องอ่านบางรุ่นอ่านไม่ออก
        $side = min((float) $mapping->width, (float) $mapping->height);

        $pdf->Image($file, $x, $y, $side, $side, 'PNG');
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

    /** ขีดเส้นใต้เป็นสไตล์เดียวที่เพิ่มได้ ส่วนตัวหนาเลือกด้วยฟอนต์ THSarabunNewB */
    private function style(DocumentFieldMapping $mapping): string
    {
        return $this->option($mapping, 'underline') ? 'U' : '';
    }

    private function option(DocumentFieldMapping $mapping, string $key): mixed
    {
        $options = $mapping->options;

        return is_array($options) ? ($options[$key] ?? null) : null;
    }

    private function align(?string $align): string
    {
        return match ($align) {
            'center' => 'C',
            'right' => 'R',
            default => 'L',
        };
    }

    /**
     * ข้อความที่พร้อมเขียนลงกระดาษ — ผ่านรูปแบบที่ผู้ดูแลเลือกไว้ก่อนเสมอ
     *
     * FPDF ทำงานบนหน้ารหัส cp874 สำหรับภาษาไทย จึงต้องแปลงจาก UTF-8 ก่อนเขียน
     */
    private function text(mixed $raw, DocumentFieldMapping $mapping): string
    {
        return $this->encode($this->formatter->format($raw, $mapping->format));
    }

    private function encode(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $converted = @iconv('UTF-8', 'CP874//TRANSLIT', $text);

        return $converted === false ? $text : $converted;
    }
}
