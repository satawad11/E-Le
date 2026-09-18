<?php

namespace App\Services\DocumentEngine;

use setasign\Fpdi\Fpdi;

/**
 * ศูนย์รวมการวาดวัตถุเวกเตอร์ (Vector Graphics & Typography) บนไฟล์ PDF ของระบบ
 *
 * ทำหน้าที่เป็น Single Source of Truth สำหรับการวาด:
 * - ข้อความและข้อความหลายบรรทัด (Text & Multiline Cells)
 * - เครื่องหมายถูกเวกเตอร์ (Vector Checkmarks)
 * - เส้นลายมือและลายเซ็นดิจิทัล (Vector Strokes from Handwriting/Signature Canvas)
 * - ตราประทับรับหนังสือราชการ (Official Administrative Stamp Boxes)
 * - รหัสคิวอาร์โค้ดเวกเตอร์ (Direct Vector QR Code Matrix)
 * - โครงร่างและกรอบจองพื้นที่ (Placeholders & Outlines)
 */
class PdfVectorDrawer
{
    public const FONT_REGULAR = OverlayFonts::REGULAR;

    public const FONT_BOLD = OverlayFonts::BOLD;

    /**
     * วาดข้อความบรรทัดเดียว
     */
    public static function drawText(
        Fpdi $pdf,
        float $x,
        float $y,
        float $width,
        float $height,
        string $text,
        float $fontSize = 14.0,
        string $font = self::FONT_REGULAR,
        string $style = '',
        string $align = 'L',
        bool $shrinkToFit = false,
        float $charSpacing = 0.0,
    ): void {
        $encoded = OverlayFonts::encode($text);

        if ($encoded === '') {
            return;
        }

        $size = $fontSize;
        $pdf->SetFont($font, $style, $size);

        if ($shrinkToFit && $width > 0) {
            $extra = $charSpacing * max(0, mb_strlen($encoded) - 1);

            while ($size > 6 && ($pdf->GetStringWidth($encoded) + $extra) > $width) {
                $size -= 0.5;
                $pdf->SetFont($font, $style, $size);
            }
        }

        if ($charSpacing > 0 && $pdf instanceof OverlayPdf) {
            $pdf->setCharSpacing($charSpacing);
        }

        $pdf->SetXY($x, $y);
        $pdf->Cell($width, $height, $encoded, 0, 0, $align);

        if ($charSpacing > 0 && $pdf instanceof OverlayPdf) {
            $pdf->setCharSpacing(0);
        }
    }

    /**
     * วาดข้อความหลายบรรทัด
     */
    public static function drawMultiline(
        Fpdi $pdf,
        float $x,
        float $y,
        float $width,
        string $text,
        float $fontSize = 14.0,
        string $font = self::FONT_REGULAR,
        string $style = '',
        string $align = 'L',
        float $lineHeightFactor = 1.2,
    ): void {
        $encoded = OverlayFonts::encode($text);

        if ($encoded === '') {
            return;
        }

        $pdf->SetFont($font, $style, $fontSize);
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($width, $fontSize * $lineHeightFactor, $encoded, 0, $align);
    }

    /**
     * วาดเครื่องหมายถูกเวกเตอร์ (Vector Checkmark)
     *
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public static function drawCheckmark(
        Fpdi $pdf,
        float $x,
        float $y,
        float $size = 14.0,
        array $rgb = [0, 110, 60],
        float $lineWidth = 1.4,
    ): void {
        $pdf->SetDrawColor($rgb[0], $rgb[1], $rgb[2]);
        $pdf->SetLineWidth($lineWidth);

        $pdf->Line($x, $y + ($size * 0.55), $x + ($size * 0.38), $y + $size);
        $pdf->Line($x + ($size * 0.38), $y + $size, $x + $size, $y);

        $pdf->SetDrawColor(0, 0, 0);
    }

    /**
     * ลายมือและลายเซ็น — วาดเป็นเส้นเวกเตอร์จากชุดพิกัด strokes
     *
     * @param  array<int, array<int, array{x: float|int, y: float|int}>>  $strokes
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public static function drawStrokes(
        Fpdi $pdf,
        float $baseX,
        float $baseY,
        array $strokes,
        float $lineWidth = 1.2,
        array $rgb = [0, 0, 90],
    ): void {
        if ($strokes === []) {
            return;
        }

        $pdf->SetDrawColor($rgb[0], $rgb[1], $rgb[2]);
        $pdf->SetLineWidth($lineWidth);

        foreach ($strokes as $stroke) {
            if (! is_array($stroke) || count($stroke) < 2) {
                continue;
            }

            $previous = null;

            foreach ($stroke as $point) {
                if (! isset($point['x'], $point['y'])) {
                    continue;
                }

                $current = [
                    $baseX + (float) $point['x'],
                    $baseY + (float) $point['y'],
                ];

                if ($previous !== null) {
                    $pdf->Line($previous[0], $previous[1], $current[0], $current[1]);
                }

                $previous = $current;
            }
        }

        $pdf->SetDrawColor(0, 0, 0);
    }

    /**
     * ตราประทับรับหนังสือราชการ — กรอบพร้อมข้อความตามระเบียบงานสารบรรณ
     *
     * @param  array<string, string>  $fields  เช่น ['เลขรับ' => '0001/2569', 'วันที่' => '16/09/2569']
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public static function drawStampBox(
        Fpdi $pdf,
        float $x,
        float $y,
        float $width,
        float $height,
        string $title = 'รับหนังสือ',
        array $fields = [],
        array $rgb = [128, 0, 0],
    ): void {
        $pdf->SetDrawColor($rgb[0], $rgb[1], $rgb[2]);
        $pdf->SetLineWidth(1);
        $pdf->Rect($x, $y, $width, $height);

        $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
        $pdf->SetFont(self::FONT_BOLD, '', 12);
        $pdf->SetXY($x + 4, $y + 4);
        $pdf->Cell($width - 8, 14, OverlayFonts::encode($title), 0, 2);

        $pdf->SetFont(self::FONT_REGULAR, '', 11);

        foreach ($fields as $label => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $pdf->SetX($x + 4);
            $pdf->Cell($width - 8, 13, OverlayFonts::encode("{$label} {$value}"), 0, 2);
        }

        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * QR สำหรับตรวจสอบหนังสือ — วาดเป็นสี่เหลี่ยมเวกเตอร์โดยตรง
     */
    public static function drawQrMatrix(
        Fpdi $pdf,
        float $x,
        float $y,
        float $size,
        mixed $matrix,
    ): void {
        $modules = $matrix->getSize();
        $module = $size / $modules;

        $pdf->SetFillColor(0, 0, 0);

        for ($row = 0; $row < $modules; $row++) {
            for ($column = 0; $column < $modules; $column++) {
                if (! $matrix->check($column, $row)) {
                    continue;
                }

                $pdf->Rect(
                    $x + ($column * $module),
                    $y + ($row * $module),
                    $module,
                    $module,
                    'F',
                );
            }
        }
    }

    /**
     * กรอบจองพื้นที่ / โครงเปล่า (Placeholder)
     */
    public static function drawPlaceholder(
        Fpdi $pdf,
        float $x,
        float $y,
        float $width,
        float $height,
        string $label,
    ): void {
        $pdf->SetDrawColor(150, 150, 150);
        $pdf->SetLineWidth(0.5);
        $pdf->Rect($x, $y, $width, $height);

        $pdf->SetFont(self::FONT_REGULAR, '', 9);
        $pdf->SetXY($x, $y + ($height / 2) - 6);
        $pdf->Cell($width, 12, OverlayFonts::encode($label), 0, 0, 'C');
    }
}
