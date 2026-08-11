<?php

namespace App\Services\Saraban;

use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfReader;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class PdfSignatureService
{
    /**
     * Stamp a signature onto a PDF file.
     *
     * @param string $pdfPath Path to the original PDF in storage.
     * @param string $signatureImagePath Path to the signature image.
     * @param array $options Signature options like x, y, page, hash, signer_name, timestamp.
     * @return string Path to the newly created stamped PDF.
     */
    public function __construct(protected ?AttachmentStorage $storage = null)
    {
        $this->storage = $storage ?: new AttachmentStorage();
    }

    public function stampSignature(string $pdfPath, ?string $signatureImagePath, array $options): string
    {
        $pdfDisk = $this->storage->resolveDisk($pdfPath);

        if (!$pdfDisk) {
            throw new \Exception("PDF file not found at: {$pdfPath}");
        }

        // We assume signature is an image if they have one, otherwise we just stamp text
        $signatureDisk = $signatureImagePath ? $this->storage->resolveDisk($signatureImagePath) : null;
        $hasImage = (bool) $signatureDisk;

        $pdfFullPath = Storage::disk($pdfDisk)->path($pdfPath);

        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($pdfFullPath);

        $targetPage = $options['page'] ?? $pageCount; // default to last page if not provided
        $x = $options['x'] ?? 150; // default X
        $y = $options['y'] ?? 250; // default Y

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            // Get size of the imported page
            $size = $pdf->getTemplateSize($templateId);
            
            // Add a page with the same orientation and size
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);

            // Stamp signature only on the target page
            if ($pageNo == $targetPage) {
                // Add signature image if available
                if ($hasImage) {
                    $sigFullPath = Storage::disk($signatureDisk)->path($signatureImagePath);
                    // Get image info to maintain aspect ratio (optional)
                    // For now, stamp with a fixed width of 40mm
                    $pdf->Image($sigFullPath, $x, $y, 40);
                }

                // Add text watermark below signature
                // Use THSarabunNew if registered, or fallback to Arial
                try {
                    $pdf->AddFont('THSarabunNew', '', 'THSarabunNew.php');
                    $pdf->SetFont('THSarabunNew', '', 14);
                } catch (\Exception $e) {
                    $pdf->SetFont('Arial', '', 10);
                }
                $pdf->SetTextColor(0, 0, 0);

                if (!empty($options['text'])) {
                    // For text mode endorsement
                    $textY = $y;
                    $lines = explode("\n", $options['text']);
                    foreach ($lines as $line) {
                        $pdf->SetXY($x, $textY);
                        $lineText = mb_convert_encoding(trim($line), 'CP874', 'UTF-8');
                        $pdf->Cell(80, 6, $lineText, 0, 1, 'L');
                        $textY += 6;
                    }
                    $y = $textY + 4; // Shift Y for signature info
                }

                $pdf->SetFont('Arial', '', 8);
                $pdf->SetTextColor(100, 100, 100);
                
                $signerName = $options['signer_name'] ?? 'Unknown';
                $timestamp = $options['timestamp'] ?? date('Y-m-d H:i:s');
                $hash = $options['hash'] ?? '';
                
                // Truncate hash for display
                $shortHash = substr($hash, 0, 16) . '...';

                // Y offset for text
                $textY = $hasImage ? $y + 15 : $y;

                $pdf->SetXY($x, $textY);
                // Note: iconv is used to convert Thai to CP874 for FPDF if Thai font is not embedded,
                // but since we are using standard Arial, we might only be able to show ASCII or basic chars safely.
                $nameText = mb_convert_encoding("Signed by: {$signerName}", 'CP874', 'UTF-8');
                $pdf->Cell(40, 4, $nameText, 0, 1, 'C');
                
                $pdf->SetXY($x, $textY + 4);
                $pdf->Cell(40, 4, $timestamp, 0, 1, 'C');
                
                if ($hash) {
                    $pdf->SetXY($x, $textY + 8);
                    $pdf->Cell(40, 4, "Ref: {$shortHash}", 0, 1, 'C');
                }
            }
        }

        // Save the new PDF บน disk ส่วนตัวเสมอ (ห้ามหลุดออก /storage สาธารณะ)
        $filename = 'signed_' . time() . '_' . basename($pdfPath);

        return $this->storage->putContents($filename, $pdf->Output('S'));
    }
}
