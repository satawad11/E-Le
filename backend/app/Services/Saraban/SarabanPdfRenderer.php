<?php

namespace App\Services\Saraban;

use App\Models\Saraban\DocumentAnnotation;
use App\Models\Saraban\DocumentVersion;
use App\Support\StoredFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use setasign\Fpdi\Fpdi;

/**
 * เรนเดอร์วัตถุที่วางไว้ลงบนไฟล์ PDF จริง — ที่ฝั่ง server เท่านั้น (SoT §14)
 *
 * **ทำไมต้องเรนเดอร์ที่ server:** ภาพที่ได้จากเบราว์เซอร์เป็นภาพแรสเตอร์ที่ขึ้นกับ
 * ความละเอียดจอ ฟอนต์ที่เครื่องนั้นมี และระดับการซูมของคนกด — หนังสือราชการ
 * ฉบับเดียวกันจะออกมาไม่เหมือนกันในแต่ละเครื่อง และข้อความในไฟล์จะค้นหาไม่ได้
 * SoT §14 จึงห้ามใช้ภาพจากเบราว์เซอร์เป็น Final PDF โดยเด็ดขาด
 *
 * ตัวนี้ **ไม่ใช่การสร้าง PDF engine ใหม่** — ใช้ FPDI, ฟอนต์ไทย และระบบพิกัด
 * PDF point ชุดเดียวกับ `PdfOverlayRenderer` ของ Document Engine เดิมทุกประการ
 *
 * ที่แยกคลาสเพราะ **แหล่งข้อมูลต่างกันคนละชนิด**: `PdfOverlayRenderer` วาดจาก
 * `DocumentFieldMapping` ของแม่แบบที่ผู้ดูแลตั้งไว้ล่วงหน้า ส่วนตัวนี้วาดจาก
 * วัตถุที่ผู้ใช้ลากวางบนหนังสือที่อัปโหลดเข้ามา การยัดสองแหล่งเข้าคลาสเดียว
 * จะทำให้เส้นทางของโมดูลการลาและ Document Engine เสี่ยงพังไปด้วยโดยไม่จำเป็น
 */
class SarabanPdfRenderer
{
    private const FONT_REGULAR = 'THSarabunNew';

    private const FONT_BOLD = 'THSarabunNewB';

    /**
     * วางวัตถุทั้งหมดของเวอร์ชันนี้ลงบนไฟล์ แล้วคืนเนื้อไฟล์ PDF ใหม่
     *
     * ไฟล์ต้นฉบับไม่ถูกแตะ — ผู้เรียกเป็นผู้บันทึกผลเป็นเวอร์ชันใหม่ (SoT §8)
     *
     * @param  iterable<int,DocumentAnnotation>  $extra  วัตถุที่ยังไม่ได้บันทึก
     *                                                   ใช้สำหรับตัวอย่างก่อนยืนยันเท่านั้น
     */
    public function render(DocumentVersion $version, iterable $extra = []): string
    {
        $disk = Storage::disk(StoredFile::DISK);

        if (blank($version->pdf_file_path) || ! $disk->exists($version->pdf_file_path)) {
            throw new RuntimeException('ไม่พบไฟล์ของเวอร์ชันนี้');
        }

        /*
         * วัตถุที่ยังไม่ได้บันทึกถูกวาด **ทับ** ของที่บันทึกไว้แล้ว ตามลำดับเดียวกับ
         * ตอนบันทึกจริง — ตัวอย่างจึงตรงกับผลลัพธ์ ไม่ใช่ใกล้เคียง
         */
        $annotations = DocumentAnnotation::query()
            ->where('document_version_id', $version->id)
            ->orderBy('page')
            ->get()
            ->concat($extra);

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

            foreach ($annotations->where('page', $page) as $annotation) {
                $this->draw($pdf, $annotation);
            }
        }

        return $pdf->Output('S');
    }

    private function draw(Fpdi $pdf, DocumentAnnotation $annotation): void
    {
        match ($annotation->type) {
            DocumentAnnotation::TYPE_STAMP => $this->drawStamp($pdf, $annotation),
            DocumentAnnotation::TYPE_DATE,
            DocumentAnnotation::TYPE_TEXT,
            DocumentAnnotation::TYPE_NOTE => $this->drawText($pdf, $annotation),
            DocumentAnnotation::TYPE_HANDWRITING,
            DocumentAnnotation::TYPE_SIGNATURE => $this->drawStrokes($pdf, $annotation),
            DocumentAnnotation::TYPE_CHECKMARK => $this->drawCheckmark($pdf, $annotation),
            DocumentAnnotation::TYPE_QR => $this->drawQrCode($pdf, $annotation),
            default => null,
        };
    }

    /**
     * ตราประทับรับหนังสือ — กรอบพร้อมข้อความตามระเบียบงานสารบรรณ
     *
     * วาดด้วยเส้นและตัวอักษรจริง ไม่ใช่ภาพ เพื่อให้คมทุกระดับการขยายและ
     * ให้ข้อความในตรายังค้นหาได้ในไฟล์
     */
    private function drawStamp(Fpdi $pdf, DocumentAnnotation $annotation): void
    {
        $width = $annotation->width ?: 150.0;
        $height = $annotation->height ?: 60.0;
        $payload = $annotation->payload ?? [];

        $pdf->SetDrawColor(128, 0, 0);
        $pdf->SetLineWidth(1);
        $pdf->Rect($annotation->x, $annotation->y, $width, $height);

        $pdf->SetTextColor(128, 0, 0);
        $pdf->SetFont(self::FONT_BOLD, '', 12);
        $pdf->SetXY($annotation->x + 4, $annotation->y + 4);
        $pdf->Cell($width - 8, 14, $this->encode($payload['title'] ?? 'รับหนังสือ'), 0, 2);

        $pdf->SetFont(self::FONT_REGULAR, '', 11);

        foreach ([
            'เลขรับ' => $payload['register_number'] ?? '',
            'วันที่' => $payload['received_date'] ?? '',
            'เวลา' => $payload['received_time'] ?? '',
        ] as $label => $value) {
            if ($value === '') {
                continue;
            }

            $pdf->SetX($annotation->x + 4);
            $pdf->Cell($width - 8, 13, $this->encode("{$label} {$value}"), 0, 2);
        }

        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * ลายมือและลายเซ็น — วาดเป็น **เส้นเวกเตอร์จากชุดพิกัด** ไม่ใช่วางภาพ (SoT §14)
     *
     * หน้าเว็บส่งมาเป็นพิกัดของเส้นที่ผู้ใช้ลาก (จาก `signature_pad`) แปลงเป็น
     * PDF point แล้ว — ไม่ได้ส่งภาพมา จึงยังไม่ขัดกับข้อห้ามเรื่องภาพจากเบราว์เซอร์
     * และได้ผลที่คมทุกระดับการขยายเพราะเป็นเวกเตอร์
     *
     * payload: `{ strokes: [ [ {x,y}, ... ], ... ], line_width?: float }`
     * พิกัดในแต่ละจุดเป็นระยะจากมุมซ้ายบนของ **กรอบวัตถุ** ไม่ใช่ของหน้ากระดาษ
     * การย้ายกรอบจึงย้ายลายมือทั้งชุดไปด้วยโดยไม่ต้องคำนวณใหม่ทีละจุด
     */
    private function drawStrokes(Fpdi $pdf, DocumentAnnotation $annotation): void
    {
        $payload = $annotation->payload ?? [];
        $strokes = $payload['strokes'] ?? [];

        if (! is_array($strokes) || $strokes === []) {
            return;
        }

        $pdf->SetDrawColor(0, 0, 90);
        $pdf->SetLineWidth((float) ($payload['line_width'] ?? 1.2));

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
                    $annotation->x + (float) $point['x'],
                    $annotation->y + (float) $point['y'],
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
     * QR สำหรับตรวจสอบหนังสือ — วาดเป็น **สี่เหลี่ยมเวกเตอร์** ไม่ใช่ภาพ (SoT §12, §14)
     *
     * แปลงเมทริกซ์ของ QR เป็นช่องสี่เหลี่ยมทึบทีละช่อง จึงได้ผลที่คมทุกระดับ
     * การขยายและไม่มีภาพแรสเตอร์อยู่ในไฟล์เลย — รักษาข้อบังคับเดียวกับที่ใช้กับ
     * ลายเซ็นและลายมือ
     *
     * `payload.hash` คือรหัสตรวจสอบของหนังสือ ซึ่งระบบเป็นผู้ออกให้ตอนออกหนังสือ
     * ไม่ใช่ค่าที่ผู้ใช้พิมพ์เข้ามา
     */
    private function drawQrCode(Fpdi $pdf, DocumentAnnotation $annotation): void
    {
        $hash = $annotation->payload['hash'] ?? null;

        if (blank($hash)) {
            $this->drawPlaceholder($pdf, $annotation, 'QR');

            return;
        }

        $matrix = app(DocumentVerificationService::class)->matrixFor((string) $hash);
        $modules = $matrix->getSize();
        $size = $annotation->width ?: 72.0;
        $module = $size / $modules;

        $pdf->SetFillColor(0, 0, 0);

        for ($row = 0; $row < $modules; $row++) {
            for ($column = 0; $column < $modules; $column++) {
                if (! $matrix->check($column, $row)) {
                    continue;
                }

                $pdf->Rect(
                    $annotation->x + ($column * $module),
                    $annotation->y + ($row * $module),
                    $module,
                    $module,
                    'F',
                );
            }
        }
    }

    private function drawCheckmark(Fpdi $pdf, DocumentAnnotation $annotation): void
    {
        $size = $annotation->width ?: 14.0;

        $pdf->SetDrawColor(0, 110, 60);
        $pdf->SetLineWidth(1.4);
        $pdf->Line($annotation->x, $annotation->y + ($size * 0.55), $annotation->x + ($size * 0.38), $annotation->y + $size);
        $pdf->Line($annotation->x + ($size * 0.38), $annotation->y + $size, $annotation->x + $size, $annotation->y);
        $pdf->SetDrawColor(0, 0, 0);
    }

    private function drawText(Fpdi $pdf, DocumentAnnotation $annotation): void
    {
        $payload = $annotation->payload ?? [];
        $text = trim((string) ($payload['text'] ?? ''));

        if ($text === '') {
            return;
        }

        $pdf->SetFont(self::FONT_REGULAR, '', (float) ($payload['font_size'] ?? 14));
        $pdf->SetXY($annotation->x, $annotation->y);
        $pdf->Cell($annotation->width ?: 200.0, $annotation->height ?: 16.0, $this->encode($text), 0, 0);
    }

    /**
     * ที่ว่างสำหรับวัตถุที่ยังไม่ถึงเช็คพอยต์ของมัน (QR อยู่ที่ INT-12)
     *
     * วาดกรอบไว้ให้เห็นว่าจองพื้นที่ไว้แล้ว ดีกว่าเงียบไปเฉย ๆ จนคนวางไม่รู้ว่า
     * สิ่งที่วางไปหายไปไหน
     */
    private function drawPlaceholder(Fpdi $pdf, DocumentAnnotation $annotation, string $label): void
    {
        $size = $annotation->width ?: 60.0;

        $pdf->SetDrawColor(150, 150, 150);
        $pdf->SetLineWidth(0.5);
        $pdf->Rect($annotation->x, $annotation->y, $size, $annotation->height ?: $size);

        $pdf->SetFont(self::FONT_REGULAR, '', 9);
        $pdf->SetXY($annotation->x, $annotation->y + ($size / 2) - 6);
        $pdf->Cell($size, 12, $label, 0, 0, 'C');
    }

    /**
     * ฟอนต์ไทยของโครงการเก็บสตริงเป็น cp874 — ต้องแปลงก่อนส่งให้ FPDF
     */
    private function encode(string $text): string
    {
        return iconv('UTF-8', 'cp874//TRANSLIT', $text) ?: $text;
    }

    /**
     * FPDF อ่าน `FPDF_FONTPATH` ตอนสร้างอ็อบเจกต์เท่านั้น จึงต้องประกาศก่อน `new Fpdi`
     */
    private function bootFontPath(): void
    {
        $dir = storage_path('fonts/fpdf');

        if (! is_file($dir.'/'.self::FONT_REGULAR.'.json') && ! is_file($dir.'/'.self::FONT_REGULAR.'.php')) {
            throw new RuntimeException('ยังไม่ได้ติดตั้งฟอนต์ไทย — รัน `php artisan document:install-fonts` ก่อน');
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
}
