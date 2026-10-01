<?php

namespace App\Services\DocumentEngine;

use App\Models\Document\DocumentFieldDefinition;
use App\Models\Document\DocumentFieldMapping;

/**
 * ค่าตัวอย่างสำหรับพรีวิวแบบฟอร์มจากหน้าออกแบบ
 *
 * เดิมพรีวิวใส่ "ชื่อโค้ดของฟิลด์" ลงในช่อง (`employee.full_name`) ซึ่งสั้นและเป็นภาษาอังกฤษ
 * ผู้ดูแลจึงตรวจไม่ได้เลยว่าข้อความจริงจะล้นช่อง ตัดบรรทัด หรือทับช่องข้าง ๆ หรือไม่
 *
 * ค่าที่นี่จึงเป็นข้อความไทย **ยาวตามกรณีจริงที่ยาวที่สุดเท่าที่พบได้** เช่น ชื่อพร้อมคำนำหน้า
 * และวิทยฐานะ เพื่อให้พรีวิวเป็นการทดสอบความพอดีของช่อง ไม่ใช่แค่ภาพประกอบ
 *
 * ไม่แตะข้อมูลจริงของผู้ใดทั้งสิ้น — ทุกค่าเป็นข้อความสมมุติ
 */
class PreviewSampleValues
{
    /** ค่าตามกลุ่มของรหัสฟิลด์ (จับคู่แบบ "ขึ้นต้นด้วย") */
    private const BY_PREFIX = [
        'personnel.full_name' => 'นางสาวกัญญาภัทร ศรีสุวรรณวิชัย',
        'personnel.position' => 'ครู วิทยฐานะครูชำนาญการพิเศษ',
        'personnel.academic_standing' => 'ครูชำนาญการพิเศษ',
        'personnel.type' => 'ข้าราชการครูและบุคลากรทางการศึกษา',
        'personnel.code' => 'T-2569-0012',
        'user.name' => 'นางสาวกัญญาภัทร ศรีสุวรรณวิชัย',
        'user.email' => 'kanyapat.s@vocational.ac.th',
        'user.phone' => '๐๘๑-๒๓๔-๕๖๗๘',
        'user.id_card_number' => '๑-๑๐๐๔-๐๐๑๒๓-๔๕-๖',
        'org_unit.name' => 'แผนกวิชาเทคโนโลยีสารสนเทศ',
        'org_unit.code' => 'IT-DEPT',
        'org_unit.full_path' => 'ฝ่ายวิชาการ / แผนกวิชาเทคโนโลยีสารสนเทศ',
        'system.today_thai' => '๑๖ กันยายน ๒๕๖๙',
        'system.today_short_thai' => '๑๖/๐๙/๒๕๖๙',
        'system.current_year_th' => '๒๕๖๙',
        'employee.full_name' => 'นางสาวกัญญาภัทร ศรีสุวรรณวิชัย',
        'employee.position' => 'ครู วิทยฐานะครูชำนาญการพิเศษ',
        'employee.academic_rank' => 'ชำนาญการพิเศษ',
        'employee.department' => 'แผนกวิชาเทคโนโลยีสารสนเทศ',
        'employee.division' => 'งานบุคลากร',
        'employee.phone' => '๐๘๑-๒๓๔-๕๖๗๘',
        'leave.type' => 'ลากิจส่วนตัวเพื่อดูแลบิดามารดา',
        'leave.reason' => 'บิดาเข้ารับการผ่าตัดที่โรงพยาบาลประจำจังหวัด จำเป็นต้องดูแลอย่างใกล้ชิดตลอดช่วงพักฟื้น',
        'leave.contact_address' => 'บ้านเลขที่ ๙๙/๑ หมู่ ๔ ตำบลในเมือง อำเภอเมือง จังหวัดนครราชสีมา ๓๐๐๐๐',
        'leave.document_number' => 'กจ ๐๐๑๒/๒๕๖๙',
        'leave.total_days' => '3',
        'organization.name' => 'วิทยาลัยเทคนิคตัวอย่างเฉลิมพระเกียรติ',
        'organization.affiliation' => 'สำนักงานคณะกรรมการการอาชีวศึกษา',
        'organization.director_name' => 'นายประเสริฐ วิทยาเลิศสกุลชัย',
        'organization.director_position' => 'ผู้อำนวยการวิทยาลัยเทคนิคตัวอย่างเฉลิมพระเกียรติ',
        'approval.' => 'นางสมหญิง ตรวจสอบการลา',
        'stats.' => '12',
        'extra.' => 'ข้อความตัวอย่างของช่องเฉพาะแบบฟอร์ม',
    ];

    /**
     * ค่าตัวอย่างของทุกฟิลด์ที่วางไว้บนแบบฟอร์ม
     *
     * @param  iterable<int, DocumentFieldMapping>  $mappings
     * @return array<string, mixed>
     */
    public function forMappings(iterable $mappings): array
    {
        $labels = DocumentFieldDefinition::pluck('label_th', 'code');
        $values = [];

        foreach ($mappings as $mapping) {
            $values[$mapping->field_code] = $this->valueFor(
                (string) $mapping->field_code,
                (string) $mapping->field_type,
                $labels[$mapping->field_code] ?? null,
            );
        }

        return $values;
    }

    private function valueFor(string $code, string $type, ?string $label): mixed
    {
        // ชนิดฟิลด์เป็นตัวกำหนดก่อน เพราะค่าที่ผิดชนิดทำให้พรีวิวหลอกตา
        switch ($type) {
            case 'CHECKBOX':
                return true;
            case 'DATE':
                // ค่าดิบ เพื่อให้เห็นผลของรูปแบบวันที่ที่ตั้งไว้จริง
                return now()->toDateString();
            case 'NUMBER':
                return '12';
            case 'SIGNATURE':
            case 'IMAGE':
                return '';
            case 'QR_CODE':
                return rtrim((string) config('app.url'), '/').'/verify-leave/PREVIEW';
        }

        foreach (self::BY_PREFIX as $prefix => $value) {
            if (str_starts_with($code, $prefix)) {
                return $value;
            }
        }

        if ($type === 'MULTILINE_TEXT') {
            return 'ข้อความตัวอย่างที่ยาวพอจะทดสอบการตัดบรรทัดในช่องนี้ หากพิมพ์ออกมาแล้วล้นกรอบ แปลว่าต้องขยายช่องหรือเปลี่ยนวิธีจัดการข้อความยาว';
        }

        return $label ?? $code;
    }
}
