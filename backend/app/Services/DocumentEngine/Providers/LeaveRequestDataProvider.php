<?php

namespace App\Services\DocumentEngine\Providers;

use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Services\DocumentEngine\DocumentDataProvider;
use App\Services\LeaveDocumentFields;

/**
 * ข้อมูลใบลาหนึ่งฉบับสำหรับเติมลงแบบฟอร์ม
 *
 * ห่อ `LeaveDocumentFields` ที่ระบบเดิมใช้อยู่ แล้วแปลงเป็นชื่อฟิลด์มาตรฐาน
 * แบบ dot notation (`employee.full_name`) ตาม Field Dictionary กลาง
 * โดยยัง**คงคีย์แบบเดิมไว้ทั้งหมด** เพื่อให้แม่แบบ HTML ที่ผู้ดูแลเขียนไว้แล้วใช้ได้ต่อ
 *
 * ข้อมูลผู้อนุมัติอ่านจาก `leave_approvals` ซึ่งเป็น snapshot ณ เวลาที่อนุมัติ
 * ไม่ใช่จากโปรไฟล์ปัจจุบัน — เอกสารย้อนหลังจึงไม่เปลี่ยนตามการแก้ข้อมูลบุคคลภายหลัง
 */
class LeaveRequestDataProvider implements DocumentDataProvider
{
    public const CODE = 'LEAVE_REQUEST_DETAIL';

    public function __construct(private readonly LeaveDocumentFields $fields) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function module(): string
    {
        return 'leave';
    }

    /**
     * @param  array{leave?: Leave, stage?: string}  $context
     * @return array<string,mixed>
     */
    public function fields(array $context): array
    {
        $leave = $context['leave'] ?? null;

        if (! $leave instanceof Leave) {
            throw new \InvalidArgumentException('LEAVE_REQUEST_DETAIL ต้องได้รับใบลาที่จะออกเอกสาร');
        }

        $stage = $context['stage'] ?? LeaveApproval::STAGE_REQUEST;
        $legacy = $this->fields->build($leave);

        return array_merge($legacy, $this->canonical($leave, $legacy, $stage));
    }

    /**
     * @param  array<string,mixed>  $legacy
     * @return array<string,mixed>
     */
    private function canonical(Leave $leave, array $legacy, string $stage): array
    {
        $approvals = $leave->relationLoaded('allApprovals')
            ? $leave->allApprovals
            : $leave->allApprovals()->orderBy('step_order')->get();

        $chain = $approvals->where('stage', $stage)->sortBy('step_order')->values();

        $canonical = [
            'employee.full_name' => $legacy['user_name'] ?? '',
            'employee.position' => $legacy['position'] ?? '',
            'employee.academic_rank' => $legacy['level'] ?? '',
            'employee.department' => $legacy['department'] ?? '',
            'employee.division' => $legacy['division'] ?? '',
            'employee.phone' => $legacy['phone'] ?? '',

            'leave.type' => $legacy['leave_type'] ?? '',
            'leave.reason' => $legacy['reason'] ?? '',
            'leave.start_date' => $legacy['start_date_thai'] ?? '',
            'leave.end_date' => $legacy['end_date_thai'] ?? '',
            'leave.total_days' => $legacy['total_days'] ?? '',
            'leave.contact_address' => $legacy['contact_address'] ?? '',
            'leave.document_number' => $legacy['doc_number'] ?? '',
            'leave.document_date' => $legacy['doc_date_thai'] ?? '',
            'leave.is_sick' => $legacy['is_sick'] ?? false,
            'leave.is_personal' => $legacy['is_personal'] ?? false,
            'leave.is_maternity' => $legacy['is_maternity'] ?? false,
            'leave.signature' => $leave->signature_path ?? '',

            'organization.name' => $legacy['school_name'] ?? '',
            'organization.affiliation' => $legacy['school_affiliation'] ?? '',
            'organization.director_name' => $legacy['director_name'] ?? '',
            'organization.director_position' => $legacy['director_position'] ?? '',
        ];

        // ผู้อนุมัติรายขั้น — ใช้ทั้งแบบระบุลำดับ (step1..n) และแบบขั้นสุดท้าย
        foreach ($chain as $index => $step) {
            $number = $index + 1;
            $canonical["approval.step{$number}.name"] = (string) ($step->approver_name ?? '');
            $canonical["approval.step{$number}.position"] = (string) ($step->approver_position ?? '');
            $canonical["approval.step{$number}.signature"] = (string) ($step->signature_path ?? '');
            $canonical["approval.step{$number}.comment"] = (string) ($step->comment ?? '');
            $canonical["approval.step{$number}.date"] = $step->approved_at?->format('d/m/').(($step->approved_at?->year ?? 0) + 543) ?: '';
        }

        $last = $chain->last(fn ($step) => $step->status === 'Approved');

        $canonical['approval.authorizer.name'] = (string) ($last->approver_name ?? '');
        $canonical['approval.authorizer.position'] = (string) ($last->approver_position ?? '');
        $canonical['approval.authorizer.signature'] = (string) ($last->signature_path ?? '');

        $first = $chain->first();
        $canonical['approval.supervisor.name'] = (string) ($first->approver_name ?? '');
        $canonical['approval.supervisor.position'] = (string) ($first->approver_position ?? '');
        $canonical['approval.supervisor.signature'] = (string) ($first->signature_path ?? '');

        return $canonical;
    }

    /**
     * @return array<int,array{code:string,label_th:string,group_th:string,data_type:string,format?:string}>
     */
    public function catalog(): array
    {
        $rows = [
            ['employee.full_name', 'ชื่อ-สกุลผู้ยื่น (พร้อมคำนำหน้า)', 'ผู้ยื่น', 'TEXT'],
            ['employee.position', 'ตำแหน่ง', 'ผู้ยื่น', 'TEXT'],
            ['employee.academic_rank', 'ระดับ/วิทยฐานะ', 'ผู้ยื่น', 'TEXT'],
            ['employee.department', 'สังกัด', 'ผู้ยื่น', 'TEXT'],
            ['employee.division', 'แผนก/งาน', 'ผู้ยื่น', 'TEXT'],
            ['employee.phone', 'เบอร์โทรศัพท์', 'ผู้ยื่น', 'TEXT'],

            ['leave.type', 'ประเภทการลา', 'การลา', 'TEXT'],
            ['leave.reason', 'เหตุผลการลา', 'การลา', 'MULTILINE_TEXT'],
            ['leave.start_date', 'วันที่เริ่มลา', 'การลา', 'DATE'],
            ['leave.end_date', 'วันที่สิ้นสุดการลา', 'การลา', 'DATE'],
            ['leave.total_days', 'จำนวนวันลา', 'การลา', 'NUMBER'],
            ['leave.contact_address', 'ที่อยู่ติดต่อระหว่างลา', 'การลา', 'MULTILINE_TEXT'],
            ['leave.document_number', 'เลขที่ใบลา', 'การลา', 'TEXT'],
            ['leave.document_date', 'วันที่เขียนใบลา', 'การลา', 'DATE'],
            ['leave.is_sick', 'ติ๊กช่อง "ลาป่วย"', 'การลา', 'CHECKBOX'],
            ['leave.is_personal', 'ติ๊กช่อง "ลากิจส่วนตัว"', 'การลา', 'CHECKBOX'],
            ['leave.is_maternity', 'ติ๊กช่อง "ลาคลอดบุตร"', 'การลา', 'CHECKBOX'],
            ['leave.signature', 'ลายเซ็นผู้ยื่น', 'การลา', 'SIGNATURE'],

            ['approval.supervisor.name', 'ชื่อผู้อนุมัติขั้นแรก', 'การอนุมัติ', 'TEXT'],
            ['approval.supervisor.position', 'ตำแหน่งผู้อนุมัติขั้นแรก', 'การอนุมัติ', 'TEXT'],
            ['approval.supervisor.signature', 'ลายเซ็นผู้อนุมัติขั้นแรก', 'การอนุมัติ', 'SIGNATURE'],
            ['approval.authorizer.name', 'ชื่อผู้อนุมัติขั้นสุดท้าย', 'การอนุมัติ', 'TEXT'],
            ['approval.authorizer.position', 'ตำแหน่งผู้อนุมัติขั้นสุดท้าย', 'การอนุมัติ', 'TEXT'],
            ['approval.authorizer.signature', 'ลายเซ็นผู้อนุมัติขั้นสุดท้าย', 'การอนุมัติ', 'SIGNATURE'],

            ['organization.name', 'ชื่อสถานศึกษา', 'สถานศึกษา', 'TEXT'],
            ['organization.affiliation', 'สังกัด', 'สถานศึกษา', 'TEXT'],
            ['organization.director_name', 'ชื่อผู้อำนวยการ', 'สถานศึกษา', 'TEXT'],
            ['organization.director_position', 'ตำแหน่งผู้อำนวยการ', 'สถานศึกษา', 'TEXT'],
        ];

        return array_map(fn (array $row) => [
            'code' => $row[0], 'label_th' => $row[1], 'group_th' => $row[2], 'data_type' => $row[3],
        ], $rows);
    }
}
