<?php

namespace App\Services\Saraban;

use App\Models\Saraban\DocumentAnnotation;
use App\Models\Saraban\DocumentVersion;
use App\Services\DocumentEngine\OverlayFonts;
use App\Services\DocumentEngine\OverlayPdf;
use App\Services\DocumentEngine\PdfVectorDrawer;
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
 * **การรวมศูนย์กับ Document Engine:**
 * ใช้ `OverlayPdf`, `OverlayFonts` และ `PdfVectorDrawer` ชุดเดียวกับระบบแม่แบบเอกสาร
 * เพื่อให้การประมวลผลฟอนต์ไทย พิกัดเวกเตอร์ และการวาดตราประทับ/ลายเซ็นมีความแม่นยำ
 * และสอดคล้องกัน 100%
 */
class SarabanPdfRenderer
{
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

        OverlayFonts::boot();

        $pdf = new OverlayPdf('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        OverlayFonts::register($pdf);

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
     */
    private function drawStamp(Fpdi $pdf, DocumentAnnotation $annotation): void
    {
        $width = $annotation->width ?: 150.0;
        $height = $annotation->height ?: 60.0;
        $payload = $annotation->payload ?? [];

        $fields = [];
        if (! empty($payload['register_number'])) {
            $fields['เลขรับ'] = $payload['register_number'];
        }
        if (! empty($payload['received_date'])) {
            $fields['วันที่'] = $payload['received_date'];
        }
        if (! empty($payload['received_time'])) {
            $fields['เวลา'] = $payload['received_time'];
        }

        PdfVectorDrawer::drawStampBox(
            $pdf,
            (float) $annotation->x,
            (float) $annotation->y,
            (float) $width,
            (float) $height,
            (string) ($payload['title'] ?? 'รับหนังสือ'),
            $fields,
        );
    }

    /**
     * ลายมือและลายเซ็น — วาดเป็นเส้นเวกเตอร์จากชุดพิกัด strokes
     */
    private function drawStrokes(Fpdi $pdf, DocumentAnnotation $annotation): void
    {
        $payload = $annotation->payload ?? [];
        $strokes = $payload['strokes'] ?? [];

        if (! is_array($strokes) || $strokes === []) {
            return;
        }

        PdfVectorDrawer::drawStrokes(
            $pdf,
            (float) $annotation->x,
            (float) $annotation->y,
            $strokes,
            (float) ($payload['line_width'] ?? 1.2),
        );
    }

    /**
     * QR สำหรับตรวจสอบหนังสือ — วาดเป็นสี่เหลี่ยมเวกเตอร์
     */
    private function drawQrCode(Fpdi $pdf, DocumentAnnotation $annotation): void
    {
        $hash = $annotation->payload['hash'] ?? null;

        if (blank($hash)) {
            $size = (float) ($annotation->width ?: 60.0);
            PdfVectorDrawer::drawPlaceholder(
                $pdf,
                (float) $annotation->x,
                (float) $annotation->y,
                $size,
                (float) ($annotation->height ?: $size),
                'QR',
            );

            return;
        }

        $matrix = app(DocumentVerificationService::class)->matrixFor((string) $hash);
        $size = (float) ($annotation->width ?: 72.0);

        PdfVectorDrawer::drawQrMatrix(
            $pdf,
            (float) $annotation->x,
            (float) $annotation->y,
            $size,
            $matrix,
        );
    }

    private function drawCheckmark(Fpdi $pdf, DocumentAnnotation $annotation): void
    {
        PdfVectorDrawer::drawCheckmark(
            $pdf,
            (float) $annotation->x,
            (float) $annotation->y,
            (float) ($annotation->width ?: 14.0),
        );
    }

    private function drawText(Fpdi $pdf, DocumentAnnotation $annotation): void
    {
        $payload = $annotation->payload ?? [];
        $text = trim((string) ($payload['text'] ?? ''));

        if ($text === '') {
            return;
        }

        PdfVectorDrawer::drawText(
            $pdf,
            (float) $annotation->x,
            (float) $annotation->y,
            (float) ($annotation->width ?: 200.0),
            (float) ($annotation->height ?: 16.0),
            $text,
            (float) ($payload['font_size'] ?? 14.0),
        );
    }
}
