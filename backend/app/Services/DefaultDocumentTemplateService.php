<?php

namespace App\Services;

use App\Models\DocumentTemplate;

class DefaultDocumentTemplateService
{
    /**
     * Register the official baseline templates without overwriting local customizations.
     */
    public function sync(): void
    {
        foreach ($this->templates() as $template) {
            DocumentTemplate::firstOrCreate(
                ['code' => $template['code']],
                $template
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function templates(): array
    {
        return [
            $this->leaveTemplate(
                'leave_sick_personal',
                'แบบใบลาป่วย ลาคลอดบุตร ลากิจส่วนตัว',
                'pdf.leave',
                8.0,
                12.0
            ),
            $this->leaveTemplate(
                'leave_vacation',
                'แบบใบขอลาพักผ่อน',
                'pdf.leave_vacation',
                8.0,
                12.0
            ),
            $this->leaveTemplate(
                'leave_paternity',
                'แบบใบลาไปช่วยเหลือภริยาที่คลอดบุตร',
                'pdf.leave_paternity',
                15.0,
                20.0
            ),
            $this->leaveTemplate(
                'leave_ordination',
                'แบบใบลาอุปสมบท',
                'pdf.leave_ordination',
                8.0,
                12.0
            ),
            $this->leaveTemplate(
                'leave_cancel',
                'แบบใบขอยกเลิกวันลา',
                'pdf.leave_cancel',
                15.0,
                20.0
            ),
            $this->leaveTemplate(
                'official_travel',
                'บันทึกข้อความ ขออนุญาตเดินทางไปราชการ',
                'pdf.official_travel',
                8.0,
                12.0
            ),
            [
                'code' => 'hr_employment_certificate',
                'name' => 'หนังสือรับรองการปฏิบัติงานบุคลากร (ตัวอย่าง HR)',
                'module' => 'hr',
                'content_html' => '<div class="text-center"><h2>หนังสือรับรองการปฏิบัติงาน</h2><p>ขอรับรองว่า {{ $user_name }} สังกัด {{ $department }}</p></div>',
                'is_active' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leaveTemplate(string $code, string $name, string $view, float $verticalMargin, float $horizontalMargin): array
    {
        $path = resource_path('views/'.str_replace('.', '/', $view).'.blade.php');

        return [
            'code' => $code,
            'name' => $name,
            'module' => 'leave',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'margin_top' => $verticalMargin,
            'margin_bottom' => $verticalMargin,
            'margin_left' => $horizontalMargin,
            'margin_right' => $horizontalMargin,
            'content_html' => file_get_contents($path),
            'is_active' => true,
        ];
    }
}
