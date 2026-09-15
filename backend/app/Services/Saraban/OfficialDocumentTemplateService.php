<?php

namespace App\Services\Saraban;

/**
 * บริการจัดเตรียมแม่แบบเอกสารราชการและชุดข้อความสั่งการมาตรฐานสำหรับสถานศึกษาอาชีวศึกษา
 *
 * อ้างอิงตามระเบียบสำนักนายกรัฐมนตรี ว่าด้วยงานสารบรรณ (ฉบับที่ ๔) พ.ศ. ๒๕๖๔:
 * - บันทึกข้อความ (แบบที่ ๒): ครุฑ ๑.๕ ซม. ด้านซ้ายบน
 * - หนังสือภายนอก (แบบที่ ๑): ครุฑ ๓ ซม. ตรงกลาง
 * - ชุดข้อความสั่งการและเกษียนความเห็นตามโครงสร้าง ๔ ฝ่ายอาชีวศึกษา
 */
class OfficialDocumentTemplateService
{
    /**
     * รายการแม่แบบเอกสารราชการที่ระบบรองรับ
     */
    public function getTemplates(): array
    {
        return [
            [
                'id' => 'internal_memo',
                'code' => 'MEMO',
                'name' => 'บันทึกข้อความ (ภายใน)',
                'description' => 'หนังสือติดต่อราชการภายใน ครุฑขนาด ๑.๕ ซม. ด้านซ้ายบน ตามแบบที่ ๒',
                'direction' => 'internal',
                'garuda' => [
                    'position' => 'left',
                    'size_cm' => 1.5,
                ],
                'sections' => [
                    'agency' => 'ฝ่าย[ระบุฝ่าย] งาน[ระบุงาน/แผนกวิชา] โทร. [เบอร์ภายใน]',
                    'salutation' => 'เรียน  ผู้อำนวยการ[ระบุชื่อวิทยาลัย]',
                    'background' => 'ด้วย [ระบุเหตุผล ความเป็นมา หรือข้อเท็จจริง]',
                    'objective' => 'ในการนี้ [ระบุวัตถุประสงค์ หรือรายละเอียดการดำเนินการ]',
                    'conclusion' => 'จึงเรียนมาเพื่อโปรดพิจารณา[อนุมัติ/สั่งการ/มอบหมาย]',
                ],
                'default_urgency' => 'normal',
                'default_confidentiality' => 'normal',
            ],
            [
                'id' => 'external_letter',
                'code' => 'EXTERNAL',
                'name' => 'หนังสือภายนอก',
                'description' => 'หนังสือติดต่อราชการภายนอก ครุฑขนาด ๓ ซม. กึ่งกลางหน้ากระดาษ ตามแบบที่ ๑',
                'direction' => 'outgoing',
                'garuda' => [
                    'position' => 'center',
                    'size_cm' => 3.0,
                ],
                'sections' => [
                    'agency' => 'วิทยาลัย[ระบุชื่อวิทยาลัย] สังกัดสำนักงานคณะกรรมการการอาชีวศึกษา',
                    'salutation' => 'เรียน  [ระบุตำแหน่งผู้รับ]',
                    'reference' => 'อ้างถึง  [หนังสือที่เคยติดต่อกัน ถ้ามี]',
                    'enclosures' => 'สิ่งที่ส่งมาด้วย  [รายการสิ่งที่ส่งมาด้วย ถ้ามี]',
                    'background' => 'ด้วย [ระบุเหตุผล ความเป็นมา หรือข้อเท็จจริง]',
                    'objective' => 'ในการนี้ [ระบุวัตถุประสงค์ หรือสิ่งที่ขอความร่วมมือ]',
                    'conclusion' => 'จึงเรียนมาเพื่อโปรด[พิจารณา/ดำเนินการ/ทราบ]',
                    'valediction' => 'ขอแสดงความนับถือ',
                    'signatory_title' => 'ผู้อำนวยการวิทยาลัย[ระบุชื่อวิทยาลัย]',
                    'contact' => 'งานสารบรรณ ฝ่ายบริหารทรัพยากร โทร. [ระบุเบอร์] โทรสาร [ระบุเบอร์] ไปรษณีย์อิเล็กทรอนิกส์ [ระบุอีเมลกลาง]',
                ],
                'default_urgency' => 'normal',
                'default_confidentiality' => 'normal',
            ],
        ];
    }

    /**
     * ดึงแม่แบบตามรหัส (ID)
     */
    public function getTemplateById(string $id): ?array
    {
        foreach ($this->getTemplates() as $tpl) {
            if ($tpl['id'] === $id) {
                return $tpl;
            }
        }

        return null;
    }

    /**
     * ชุดข้อความเกษียนความเห็นและสั่งการด่วนสำหรับผู้บริหารอาชีวศึกษา (๔ ฝ่าย)
     */
    public function getVocationalEndorsementPresets(): array
    {
        return [
            [
                'id' => 'acknowledge_proceed',
                'category' => 'general',
                'label' => 'ทราบ / ดำเนินการตามระเบียบ',
                'text' => 'ทราบ ดำเนินการตามระเบียบ',
            ],
            [
                'id' => 'approve_as_proposed',
                'category' => 'approval',
                'label' => 'อนุมัติ ตามเสนอ',
                'text' => 'อนุมัติ ตามเสนอ',
            ],
            [
                'id' => 'assign_resource',
                'category' => 'assignment',
                'label' => 'มอบ รอง ผอ. ฝ่ายบริหารทรัพยากร ดำเนินการ',
                'text' => 'มอบ รองผู้อำนวยการฝ่ายบริหารทรัพยากร ดำเนินการต่อไป',
                'division' => 'resource_management',
            ],
            [
                'id' => 'assign_academic',
                'category' => 'assignment',
                'label' => 'มอบ รอง ผอ. ฝ่ายวิชาการ ดำเนินการ',
                'text' => 'มอบ รองผู้อำนวยการฝ่ายวิชาการ ดำเนินการต่อไป',
                'division' => 'academic_affairs',
            ],
            [
                'id' => 'assign_student',
                'category' => 'assignment',
                'label' => 'มอบ รอง ผอ. ฝ่ายพัฒนากิจการนักเรียนฯ ดำเนินการ',
                'text' => 'มอบ รองผู้อำนวยการฝ่ายพัฒนากิจการนักเรียน นักศึกษา ดำเนินการต่อไป',
                'division' => 'student_affairs',
            ],
            [
                'id' => 'assign_planning',
                'category' => 'assignment',
                'label' => 'มอบ รอง ผอ. ฝ่ายแผนงานและความร่วมมือ ดำเนินการ',
                'text' => 'มอบ รองผู้อำนวยการฝ่ายแผนงานและความร่วมมือ ดำเนินการต่อไป',
                'division' => 'planning_and_cooperation',
            ],
            [
                'id' => 'circular_staff',
                'category' => 'circular',
                'label' => 'แจ้งเวียนบุคลากรทุกท่านเพื่อทราบ',
                'text' => 'แจ้งเวียนบุคลากรทุกท่านเพื่อทราบและถือปฏิบัติ',
            ],
            [
                'id' => 'request_info',
                'category' => 'followup',
                'label' => 'ขอข้อมูล/รายละเอียดเพิ่มเติม',
                'text' => 'ขอข้อมูลและรายละเอียดเพิ่มเติมเพื่อประกอบการพิจารณา',
            ],
        ];
    }
}
