<?php

namespace App\Services\Saraban;

use App\Models\Leave;
use App\Models\Overtime\OvertimeOrder;
use App\Services\TrustedClock;

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
    public function __construct(
        private readonly ?TrustedClock $clock = null,
    ) {}

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
            [
                'id' => 'college_order',
                'code' => 'ORDER',
                'name' => 'คำสั่งวิทยาลัย/สถานศึกษา',
                'description' => 'คำสั่งสถานศึกษา ครุฑขนาด ๓ ซม. กึ่งกลางหน้ากระดาษ ตามแบบที่ ๔',
                'direction' => 'internal',
                'garuda' => [
                    'position' => 'center',
                    'size_cm' => 3.0,
                ],
                'sections' => [
                    'title' => 'คำสั่งวิทยาลัย[ระบุชื่อวิทยาลัย]',
                    'number' => 'ที่ [เลขที่คำสั่ง]/[ปี พ.ศ.]',
                    'subject' => 'เรื่อง  [ระบุชื่อเรื่องคำสั่ง]',
                    'preamble' => 'เพื่อให้การดำเนินงาน [ระบุภารกิจ/โครงการ/งาน] ของวิทยาลัย[ระบุชื่อวิทยาลัย] เป็นไปด้วยความเรียบร้อย มีประสิทธิภาพ อาศัยอำนาจตามความในมาตรา [ระบุกฎหมาย/ระเบียบที่เกี่ยวข้อง]',
                    'body' => 'จึงแต่งตั้งคณะกรรมการ/มอบหมายหน้าที่การปฏิบัติงานดังต่อไปนี้ [ระบุรายละเอียดคำสั่ง]',
                    'effective_clause' => 'ทั้งนี้ ตั้งแต่บัดนี้เป็นต้นไป',
                    'ordered_date' => 'สั่ง ณ วันที่ [ระบุวันที่] เดือน [ระบุเดือน] พ.ศ. [ระบุปี]',
                    'signatory_title' => 'ผู้อำนวยการวิทยาลัย[ระบุชื่อวิทยาลัย]',
                ],
                'default_urgency' => 'normal',
                'default_confidentiality' => 'normal',
            ],
            [
                'id' => 'college_announcement',
                'code' => 'ANNOUNCEMENT',
                'name' => 'ประกาศวิทยาลัย/สถานศึกษา',
                'description' => 'ประกาศสถานศึกษา ครุฑขนาด ๓ ซม. กึ่งกลางหน้ากระดาษ ตามแบบที่ ๕',
                'direction' => 'internal',
                'garuda' => [
                    'position' => 'center',
                    'size_cm' => 3.0,
                ],
                'sections' => [
                    'title' => 'ประกาศวิทยาลัย[ระบุชื่อวิทยาลัย]',
                    'subject' => 'เรื่อง  [ระบุชื่อเรื่องประกาศ]',
                    'body' => 'ด้วย วิทยาลัย[ระบุชื่อวิทยาลัย] มีความประสงค์จะ [ระบุรายละเอียดการประกาศ]',
                    'announced_date' => 'ประกาศ ณ วันที่ [ระบุวันที่] เดือน [ระบุเดือน] พ.ศ. [ระบุปี]',
                    'signatory_title' => 'ผู้อำนวยการวิทยาลัย[ระบุชื่อวิทยาลัย]',
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

    /**
     * สร้างโครงร่างเอกสารราชการอัตโนมัติจากข้อมูลคำขอลา (Cross-Module Leave Bridge)
     */
    public function createDraftPayloadFromLeave(Leave $leave): array
    {
        $userName = $leave->user?->name ?? 'บุคลากร';
        $leaveTypeName = $leave->leaveType?->name ?? $leave->leaveType?->name_th ?? 'การลา';
        $startDate = $leave->start_date ? $leave->start_date->format('d/m/Y') : '-';
        $endDate = $leave->end_date ? $leave->end_date->format('d/m/Y') : '-';
        $totalDays = $leave->total_days ?? 1;

        return [
            'template_id' => 'college_order',
            'subject' => "อนุญาตให้ข้าราชการครูและบุคลากรทางการศึกษาลา{$leaveTypeName}",
            'sections' => [
                'title' => 'คำสั่งวิทยาลัยอาชีวศึกษา',
                'subject' => "เรื่อง  อนุญาตให้บุคลากรลา{$leaveTypeName}",
                'preamble' => "ด้วย {$userName} ได้ยื่นความประสงค์ขอลา{$leaveTypeName} ตั้งแต่วันที่ {$startDate} ถึงวันที่ {$endDate} รวมเป็นเวลา {$totalDays} วันทำการ ตามระเบียบว่าด้วยการลาของข้าราชการ",
                'body' => "อาศัยอำนาจตามระเบียบสำนักนายกรัฐมนตรีว่าด้วยการลาของข้าราชการ พ.ศ. ๒๕๕๕ และที่แก้ไขเพิ่มเติม จึงอนุญาตให้ {$userName} ลา{$leaveTypeName} ได้ตามความประสงค์",
                'effective_clause' => 'ทั้งนี้ ตั้งแต่วันที่ได้รับอนุมัติเป็นต้นไป',
            ],
            'metadata' => [
                'source_module' => 'leave',
                'source_id' => $leave->id,
                'source_uuid' => $leave->uuid,
            ],
        ];
    }

    /**
     * สร้างโครงร่างเอกสารราชการอัตโนมัติจากคำสั่งปฏิบัติงานล่วงเวลา (Cross-Module Overtime Bridge)
     */
    public function createDraftPayloadFromOvertime(OvertimeOrder $order): array
    {
        $orderTitle = $order->title ?? 'ภารกิจจำเป็นเร่งด่วนของสถานศึกษา';
        $now = $this->clock ? $this->clock->now() : app(TrustedClock::class)->now();
        $orderNo = $order->order_number ?? 'OT-'.$now->format('Ymd');
        $start = $order->scheduled_start ? $order->scheduled_start->format('d/m/Y H:i') : '-';
        $end = $order->scheduled_end ? $order->scheduled_end->format('d/m/Y H:i') : '-';

        return [
            'template_id' => 'college_order',
            'subject' => "ให้บุคลากรปฏิบัติงานนอกเวลาราชการ (ล่วงเวลา) {$orderTitle}",
            'sections' => [
                'title' => 'คำสั่งวิทยาลัยอาชีวศึกษา',
                'subject' => 'เรื่อง  ให้บุคลากรปฏิบัติงานนอกเวลาราชการ',
                'preamble' => "ด้วย สถานศึกษามีความจำเป็นเร่งด่วนในการดำเนินงาน {$orderTitle} (รหัสคำสั่ง {$orderNo}) ในช่วงระหว่างวันที่ {$start} ถึง {$end}",
                'body' => 'เพื่อให้การดำเนินงานดังกล่าวสำเร็จลุล่วงด้วยความเรียบร้อย มีประสิทธิภาพ จึงมอบหมายให้บุคลากรที่มีรายชื่อตามคำสั่ง ปฏิบัติหน้าที่นอกเวลาราชการอย่างเคร่งครัด',
                'effective_clause' => 'ทั้งนี้ ตั้งแต่วันที่กำหนดไว้ในคำสั่งเป็นต้นไป',
            ],
            'metadata' => [
                'source_module' => 'overtime',
                'source_id' => $order->id,
                'source_uuid' => $order->uuid,
            ],
        ];
    }

    /**
     * สร้างโครงร่างเอกสารราชการอัตโนมัติจากสรุปผลการประเมินวันลา ก.ค.ศ. (Cross-Module Evaluation Bridge)
     */
    public function createDraftPayloadFromEvaluation(array $evalSummary): array
    {
        $now = $this->clock ? $this->clock->now() : app(TrustedClock::class)->now();
        $period = $evalSummary['evaluation_period'] ?? 'รอบปัจจุบัน';
        $fiscalYear = $evalSummary['fiscal_year'] ?? $now->year + 543;
        $totalStaff = $evalSummary['total_staff'] ?? 0;
        $passedStaff = $evalSummary['passed_staff'] ?? 0;

        return [
            'template_id' => 'college_announcement',
            'subject' => "ประกาศผลการตรวจสอบเกณฑ์วันลาสะสม ก.ค.ศ. ๒๓ วัน ประจำปีงบประมาณ พ.ศ. {$fiscalYear}",
            'sections' => [
                'title' => 'ประกาศวิทยาลัยอาชีวศึกษา',
                'subject' => "เรื่อง  ผลการตรวจสอบเกณฑ์วันลาสะสม ก.ค.ศ. ๒๓ วัน {$period}",
                'body' => "ด้วย วิทยาลัยได้ดำเนินการตรวจสอบสถิติวันลาสะสมของข้าราชการครูและบุคลากรทางการศึกษา จำนวนทั้งสิ้น {$totalStaff} คน โดยมีผู้ผ่านเกณฑ์ไม่เกิน ๒๓ วัน จำนวน {$passedStaff} คน เพื่อประกอบการพิจารณาเลื่อนขั้นเงินเดือนตามหลักเกณฑ์ของ ก.ค.ศ.",
            ],
            'metadata' => [
                'source_module' => 'evaluation',
                'fiscal_year' => $fiscalYear,
                'evaluation_period' => $period,
            ],
        ];
    }
}
