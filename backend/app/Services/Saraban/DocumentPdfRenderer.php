<?php

namespace App\Services\Saraban;

use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * สร้าง PDF หนังสือราชการจากเนื้อหา HTML ที่พิมพ์ในระบบ (creation_mode = digital)
 */
class DocumentPdfRenderer
{
    public const GENERATED_NAME = 'เอกสารอิเล็กทรอนิกส์.pdf';

    public function __construct(protected AttachmentStorage $storage)
    {
    }

    /**
     * @return string path ของไฟล์บน disk ส่วนตัว
     */
    public function render(string $htmlContent): string
    {
        $pdf = Pdf::loadHTML($this->wrap($htmlContent));
        $pdf->setPaper('A4', 'portrait');

        return $this->storage->putContents('edoc_' . time() . '_' . uniqid() . '.pdf', $pdf->output());
    }

    protected function wrap(string $htmlContent): string
    {
        return '
        <html>
        <head>
            <style>
                body { font-family: "THSarabunNew", sans-serif; font-size: 16pt; padding: 2cm 1.5cm; }
                p { margin: 0; padding: 0; }
            </style>
        </head>
        <body>
            ' . $this->garudaLogoHtml() . '
            ' . $htmlContent . '
        </body>
        </html>';
    }

    protected function garudaLogoHtml(): string
    {
        $logoUrl = Setting::where('key', 'saraban_garuda_logo')->value('value');

        if (!$logoUrl) {
            return '';
        }

        $absoluteLogoPath = public_path(str_replace('/storage/', 'storage/', $logoUrl));

        if (!file_exists($absoluteLogoPath)) {
            return '';
        }

        $logoData = base64_encode(file_get_contents($absoluteLogoPath));

        return '<div style="text-align: center; margin-bottom: 20px;"><img src="data:image/png;base64,' . $logoData . '" width="60" /></div>';
    }
}
