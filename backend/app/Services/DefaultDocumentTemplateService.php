<?php

namespace App\Services;

use App\Models\DocumentTemplate;

class DefaultDocumentTemplateService
{
    /**
     * Register the official baseline templates without overwriting local customizations.
     *
     * `$overwrite` = "คืนค่าแบบฟอร์มตั้งต้น" จากหน้าผู้ดูแล — เขียนทับเนื้อหาปัจจุบัน
     * ปลอดภัยเพราะการแก้ไขจะสร้าง version ใหม่เสมอ และใบลาเก่ายัง pin เวอร์ชันเดิมไว้
     * เอกสารที่พิมพ์ไปแล้วจึงพิมพ์ซ้ำได้เหมือนเดิม
     */
    public function sync(bool $overwrite = false): void
    {
        foreach ($this->templates() as $template) {
            $existing = DocumentTemplate::firstOrCreate(
                ['code' => $template['code']],
                $template
            );

            if ($overwrite && ! $existing->wasRecentlyCreated) {
                $existing->update($template);
            }
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
                'leave_hajj',
                'แบบใบลาไปประกอบพิธีฮัจญ์',
                'pdf.leave_hajj',
                8.0,
                12.0
            ),
            $this->leaveTemplate(
                'leave_spouse',
                'แบบใบลาติดตามคู่สมรส',
                'pdf.leave_spouse',
                8.0,
                12.0
            ),
            $this->leaveTemplate(
                'leave_study',
                'แบบใบลาไปศึกษาต่อ ฝึกอบรม ดูงาน',
                'pdf.leave_study',
                8.0,
                12.0
            ),
            $this->leaveTemplate(
                'leave_military',
                'แบบรายงานลาเข้ารับการตรวจเลือกหรือเข้ารับการเตรียมพล',
                'pdf.leave_military',
                8.0,
                14.0
            ),
            $this->leaveTemplate(
                'leave_international',
                'แบบใบลาไปปฏิบัติงานในองค์การระหว่างประเทศ',
                'pdf.leave_international',
                8.0,
                14.0
            ),
            $this->leaveTemplate(
                'leave_rehabilitation',
                'แบบใบลาไปฟื้นฟูสมรรถภาพด้านอาชีพ',
                'pdf.leave_rehabilitation',
                8.0,
                14.0
            ),
            $this->leaveTemplate(
                'official_travel',
                'บันทึกข้อความ ขออนุญาตเดินทางไปราชการ',
                'pdf.official_travel',
                8.0,
                12.0
            ),
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
