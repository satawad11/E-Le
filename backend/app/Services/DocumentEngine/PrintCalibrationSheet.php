<?php

namespace App\Services\DocumentEngine;

use App\Models\DocumentTemplateVersion;
use Illuminate\Support\Facades\Storage;

/**
 * ใบสอบเทียบการพิมพ์ — หาค่าชดเชยของเครื่องพิมพ์ด้วยการวัดจากกระดาษจริง
 *
 * เดิมผู้ดูแลต้องเดาค่า `x_offset`/`y_offset`/`scale` แล้วลองพิมพ์ซ้ำไปเรื่อย ๆ
 * ใบนี้พิมพ์กากบาทสามจุดที่ระบบรู้พิกัดแน่นอน ผู้ดูแลวัดระยะจริงจากขอบกระดาษด้วยไม้บรรทัด
 * แล้วกรอกกลับเข้าระบบ ระบบคำนวณค่าชดเชยให้ตรง ๆ
 *
 * แบบจำลองความคลาดเคลื่อนของเครื่องพิมพ์: ตำแหน่งบนกระดาษ = a × ตำแหน่งที่สั่ง + b
 * วัดสองจุดในแต่ละแกนจึงหา a และ b ได้ครบ และค่าชดเชยที่ต้องตั้งคือ scale = 1/a, offset = −b/a
 */
class PrintCalibrationSheet
{
    /** พิกัดของกากบาทที่ใช้เป็นหลักอ้างอิง (PDF point จากมุมซ้ายบน) */
    public const ORIGIN_X = 72.0;

    public const ORIGIN_Y = 72.0;

    public const SPAN = 360.0;

    /** เนื้อไฟล์ PDF ของใบสอบเทียบ วางบนแบบฟอร์มต้นฉบับของเวอร์ชันนั้นเพื่อให้เห็นบริบทจริง */
    public function render(DocumentTemplateVersion $version): string
    {
        $disk = Storage::disk('private_uploads');

        if (blank($version->pdf_file_path) || ! $disk->exists($version->pdf_file_path)) {
            throw new \RuntimeException('เวอร์ชันนี้ยังไม่มีไฟล์ PDF ต้นฉบับ กรุณาอัปโหลดแบบฟอร์มก่อน');
        }

        OverlayFonts::boot();

        $pdf = new OverlayPdf('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        OverlayFonts::register($pdf);
        $pdf->setSourceFile($disk->path($version->pdf_file_path));

        $template = $pdf->importPage(1);
        $size = $pdf->getTemplateSize($template);

        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($template);

        $pdf->SetDrawColor(200, 0, 0);
        $pdf->SetTextColor(200, 0, 0);
        $pdf->SetLineWidth(0.6);
        $pdf->SetFont(OverlayFonts::REGULAR, '', 12);

        $marks = [
            ['A', self::ORIGIN_X, self::ORIGIN_Y],
            ['B', self::ORIGIN_X + self::SPAN, self::ORIGIN_Y],
            ['C', self::ORIGIN_X, self::ORIGIN_Y + self::SPAN],
        ];

        foreach ($marks as [$label, $x, $y]) {
            $this->cross($pdf, $x, $y);
            $pdf->SetXY($x + 6, $y + 2);
            $pdf->Cell(60, 12, $label, 0, 0, 'L');
        }

        $pdf->SetXY(self::ORIGIN_X, self::ORIGIN_Y + self::SPAN + 40);
        $pdf->MultiCell(
            $size['width'] - self::ORIGIN_X * 2,
            16,
            $this->encode(
                'ใบสอบเทียบการพิมพ์: วัดด้วยไม้บรรทัดจากขอบกระดาษถึงจุดตัดของกากบาท '
                .'แล้วกรอกค่าที่หน้าออกแบบแบบฟอร์ม — ระยะ A ถึง B และ A ถึง C ควรเท่ากับ 127.0 มม. พอดี '
                .'พิมพ์ด้วยขนาดจริง 100% ห้ามใช้ "ย่อให้พอดีหน้า"'
            ),
            0,
            'L',
        );

        return $pdf->Output('S');
    }

    /**
     * ค่าชดเชยที่ควรตั้ง จากระยะที่วัดได้จริง (หน่วยมิลลิเมตร)
     *
     * @return array{x_offset: float, y_offset: float, scale: float}
     */
    public function solve(float $measuredAxMm, float $measuredAyMm, float $measuredAbMm, float $measuredAcMm): array
    {
        $expectedSpan = CoordinateConverter::pointsToMm(self::SPAN);

        $scaleX = $measuredAbMm > 0 ? $expectedSpan / $measuredAbMm : 1.0;
        $scaleY = $measuredAcMm > 0 ? $expectedSpan / $measuredAcMm : 1.0;

        // โมเดลค่าชดเชยของระบบมี scale เดียวสำหรับทั้งสองแกน จึงใช้ค่าเฉลี่ย
        $scale = ($scaleX + $scaleY) / 2;

        $measuredAx = CoordinateConverter::mmToPoints($measuredAxMm);
        $measuredAy = CoordinateConverter::mmToPoints($measuredAyMm);

        // b = ตำแหน่งที่วัดได้ − a × ตำแหน่งที่สั่ง ; offset ที่ต้องตั้ง = −b/a = −b × scale
        $shiftX = $measuredAx - (self::ORIGIN_X / $scaleX);
        $shiftY = $measuredAy - (self::ORIGIN_Y / $scaleY);

        return [
            'x_offset' => round(-$shiftX * $scaleX, 2),
            'y_offset' => round(-$shiftY * $scaleY, 2),
            'scale' => round($scale, 4),
        ];
    }

    private function cross(OverlayPdf $pdf, float $x, float $y): void
    {
        $arm = 12.0;

        $pdf->Line($x - $arm, $y, $x + $arm, $y);
        $pdf->Line($x, $y - $arm, $x, $y + $arm);
    }

    private function encode(string $text): string
    {
        $converted = @iconv('UTF-8', 'CP874//TRANSLIT', $text);

        return $converted === false ? $text : $converted;
    }
}
