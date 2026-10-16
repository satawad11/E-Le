<?php

namespace Database\Seeders;

use App\Models\Leave;
use App\Models\LeaveType;
use App\Services\LeaveDocumentTemplateResolver;
use Illuminate\Database\Seeder;

/**
 * ช่องกรอกเฉพาะแบบฟอร์ม "ตั้งต้น" ของประเภทการลาที่แบบฟอร์มราชการต้องใช้
 *
 * เป็นเพียงค่าเริ่มต้นให้ใช้งานได้ทันที — ผู้ดูแลเพิ่ม/ลบ/แก้ไขได้เองจากหน้า
 * ตั้งค่าประเภทการลา โดยไม่ต้องแก้โค้ด (ห้าม hard-code นโยบายหรือแบบฟอร์ม)
 *
 * ไม่เขียนทับของเดิม: ประเภทการลาที่ตั้งช่องไว้แล้วจะถูกข้าม
 */
class LeaveFormExtraFieldSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'leave_ordination' => [
                ['key' => 'ordination_temple', 'label' => 'วัดที่อุปสมบท', 'type' => 'text', 'required' => true],
                ['key' => 'ordination_temple_address', 'label' => 'ที่ตั้งวัดที่อุปสมบท', 'type' => 'text', 'required' => false],
                ['key' => 'ordination_date', 'label' => 'วันกำหนดอุปสมบท', 'type' => 'date', 'required' => true],
                ['key' => 'retreat_temple', 'label' => 'วัดที่จำพรรษา', 'type' => 'text', 'required' => false],
                ['key' => 'retreat_temple_address', 'label' => 'ที่ตั้งวัดที่จำพรรษา', 'type' => 'text', 'required' => false],
                ['key' => 'ordained_before', 'label' => 'เคยอุปสมบทแล้วหรือไม่', 'type' => 'select', 'required' => false, 'options' => ['ยังไม่เคย', 'เคย']],
            ],
            'leave_paternity' => [
                ['key' => 'wife_name', 'label' => 'ชื่อภริยาโดยชอบด้วยกฎหมาย', 'type' => 'text', 'required' => true],
                ['key' => 'childbirth_date', 'label' => 'วันที่ภริยาคลอดบุตร', 'type' => 'date', 'required' => true],
                ['key' => 'level', 'label' => 'ระดับ/วิทยฐานะ', 'type' => 'text', 'required' => false],
            ],
            'leave_hajj' => [
                ['key' => 'hajj_before', 'label' => 'เคยไปประกอบพิธีฮัจย์แล้วหรือไม่', 'type' => 'select', 'required' => false, 'options' => ['ยังไม่เคย', 'เคย']],
            ],
            'leave_spouse' => [
                ['key' => 'level', 'label' => 'ระดับ/วิทยฐานะ', 'type' => 'text', 'required' => false],
                ['key' => 'spouse_name', 'label' => 'ชื่อ-สกุลคู่สมรส', 'type' => 'text', 'required' => true],
                ['key' => 'spouse_position', 'label' => 'ตำแหน่งของคู่สมรส', 'type' => 'text', 'required' => false],
                ['key' => 'spouse_level', 'label' => 'ระดับของคู่สมรส', 'type' => 'text', 'required' => false],
                ['key' => 'spouse_affiliation', 'label' => 'สังกัดของคู่สมรส', 'type' => 'text', 'required' => false],
                ['key' => 'spouse_country', 'label' => 'ประเทศที่คู่สมรสไปปฏิบัติราชการ/ปฏิบัติงาน', 'type' => 'text', 'required' => true],
                ['key' => 'spouse_posting_period', 'label' => 'คู่สมรสไปปฏิบัติราชการมีกำหนด (เช่น 2 ปี 0 เดือน 0 วัน)', 'type' => 'text', 'required' => false],
                ['key' => 'salary', 'label' => 'เงินเดือน (บาท)', 'type' => 'text', 'required' => false],
                ['key' => 'previous_country', 'label' => 'ครั้งสุดท้ายลาติดตามคู่สมรสไปประเทศ', 'type' => 'text', 'required' => false],
                ['key' => 'total_with_previous', 'label' => 'กรณีลาติดต่อกับครั้งก่อน รวมทั้งครั้งนี้เป็นเวลา', 'type' => 'text', 'required' => false],
            ],
            'leave_study' => [
                ['key' => 'level', 'label' => 'ระดับ/วิทยฐานะ', 'type' => 'text', 'required' => false],
                ['key' => 'study_kind', 'label' => 'ลาไปเพื่อ', 'type' => 'select', 'required' => true, 'options' => ['ศึกษา', 'ฝึกอบรม', 'ปฏิบัติการวิจัย', 'ดูงาน']],
                ['key' => 'course', 'label' => 'วิชา/หลักสูตร', 'type' => 'text', 'required' => true],
                ['key' => 'degree', 'label' => 'ขั้นปริญญา', 'type' => 'text', 'required' => false],
                ['key' => 'institution', 'label' => 'สถานศึกษา/หน่วยงานที่ไป', 'type' => 'text', 'required' => true],
                ['key' => 'country', 'label' => 'ประเทศ', 'type' => 'text', 'required' => false],
                ['key' => 'funding', 'label' => 'ทุนที่ใช้', 'type' => 'text', 'required' => false],
                ['key' => 'salary', 'label' => 'เงินเดือน (บาท)', 'type' => 'text', 'required' => false],
            ],
            'leave_military' => [
                ['key' => 'level', 'label' => 'ระดับ/วิทยฐานะ', 'type' => 'text', 'required' => false],
                ['key' => 'call_kind', 'label' => 'รายงานลาเพื่อ', 'type' => 'select', 'required' => true, 'options' => ['เข้ารับการตรวจเลือก', 'เข้ารับการเตรียมพล']],
                ['key' => 'summons_issuer', 'label' => 'หมายเรียกของ (หน่วยงาน)', 'type' => 'text', 'required' => true],
                ['key' => 'summons_number', 'label' => 'เลขที่หมายเรียก', 'type' => 'text', 'required' => false],
                ['key' => 'summons_date', 'label' => 'วันที่ของหมายเรียก', 'type' => 'date', 'required' => false],
                ['key' => 'report_place', 'label' => 'สถานที่ไปรายงานตัว', 'type' => 'text', 'required' => true],
            ],
            'leave_international' => [
                ['key' => 'level', 'label' => 'ระดับ/วิทยฐานะ', 'type' => 'text', 'required' => false],
                ['key' => 'organization_name', 'label' => 'ชื่อองค์การระหว่างประเทศ', 'type' => 'text', 'required' => true],
                ['key' => 'assigned_position', 'label' => 'ตำแหน่งที่ไปปฏิบัติงาน', 'type' => 'text', 'required' => false],
                ['key' => 'country', 'label' => 'ประเทศ', 'type' => 'text', 'required' => true],
                ['key' => 'funding', 'label' => 'ได้รับค่าตอบแทนจาก', 'type' => 'text', 'required' => false],
                ['key' => 'salary', 'label' => 'อัตราเงินเดือน (บาท)', 'type' => 'text', 'required' => false],
            ],
            'leave_rehabilitation' => [
                ['key' => 'level', 'label' => 'ระดับ/วิทยฐานะ', 'type' => 'text', 'required' => false],
                ['key' => 'injury_cause', 'label' => 'สาเหตุการบาดเจ็บ/เจ็บป่วยจากการปฏิบัติราชการ', 'type' => 'text', 'required' => true],
                ['key' => 'injury_date', 'label' => 'วันที่บาดเจ็บ/เจ็บป่วย', 'type' => 'date', 'required' => false],
                ['key' => 'course', 'label' => 'หลักสูตรฟื้นฟูสมรรถภาพ', 'type' => 'text', 'required' => true],
                ['key' => 'institution', 'label' => 'สถานที่ฝึกอบรม', 'type' => 'text', 'required' => false],
            ],
            'official_travel' => [
                ['key' => 'destination', 'label' => 'สถานที่ไปราชการ', 'type' => 'text', 'required' => true],
                ['key' => 'ref_doc_no', 'label' => 'ตามหนังสือที่', 'type' => 'text', 'required' => false],
                ['key' => 'expense_type', 'label' => 'การเบิกค่าใช้จ่าย', 'type' => 'select', 'required' => true, 'options' => ['เบิกตามสิทธิ', 'ไม่เบิกค่าใช้จ่าย', 'เบิกเฉพาะค่ายานพาหนะ']],
                ['key' => 'companion_1', 'label' => 'ผู้ร่วมเดินทาง 1', 'type' => 'text', 'required' => false],
                ['key' => 'companion_2', 'label' => 'ผู้ร่วมเดินทาง 2', 'type' => 'text', 'required' => false],
                ['key' => 'companion_3', 'label' => 'ผู้ร่วมเดินทาง 3', 'type' => 'text', 'required' => false],
            ],
        ];

        /*
         * จับคู่แบบฟอร์มเหมือนตอนพิมพ์จริง — ใช้ document_template_code ถ้าตั้งไว้ ไม่เช่นนั้นเดาจากชื่อประเภทการลา
         * (ประเภทการลาที่ยังไม่ได้เลือกแบบฟอร์มในหน้าตั้งค่าก็ได้ช่องกรอกของแบบฟอร์มที่จะพิมพ์จริง)
         */
        $resolver = app(LeaveDocumentTemplateResolver::class);

        LeaveType::whereNull('extra_fields')->get()->each(function (LeaveType $type) use ($defaults, $resolver) {
            $code = $resolver->templateCodeForLeave((new Leave)->setRelation('type', $type));

            if ($code !== null && isset($defaults[$code])) {
                $type->update(['extra_fields' => $defaults[$code]]);
            }
        });
    }
}
