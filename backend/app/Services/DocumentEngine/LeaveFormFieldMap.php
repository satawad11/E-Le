<?php

namespace App\Services\DocumentEngine;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Services\LeaveDocumentFields;
use Illuminate\Support\Arr;

/**
 * ชุดฟิลด์ของแบบฟอร์มใบลาที่วางบนหน้าออกแบบ (Visual Designer) ได้ — แหล่งเดียวของทั้งระบบ
 *
 * แต่ละฟิลด์บอกสามอย่าง:
 *   - code / label / group / type  → รายการใน Field Dictionary ที่ผู้ดูแลเลือกวางบนแบบฟอร์ม
 *   - path                        → ตัวแปรของแบบฟอร์ม HTML ที่พิมพ์ค่านี้ (ใช้หาตำแหน่งอัตโนมัติ)
 *   - ค่า                          → อ่านจากชุดตัวแปรเดียวกับแบบฟอร์ม HTML (LeaveDocumentFields)
 *
 * เพราะค่าทั้งสองเส้นทางมาจากชุดตัวแปรเดียวกัน แบบฟอร์ม PDF ที่ออกแบบเองกับแบบฟอร์ม HTML
 * จึงพิมพ์ข้อมูลเดียวกันเสมอ
 *
 * ผู้ลงนามแบ่งตามบทบาทบนแบบฟอร์มราชการ (ดู roles()):
 *   checker = ผู้ตรวจสอบ (งานบุคลากร) · commander = ผู้บังคับบัญชา · authorizer = ผู้สั่งการ (ขั้นสุดท้าย)
 */
class LeaveFormFieldMap
{
    public const ROLES = [
        'checker' => 'ผู้ตรวจสอบ',
        'commander' => 'ผู้บังคับบัญชา (ความเห็น)',
        'authorizer' => 'ผู้สั่งการ (คำสั่ง)',
    ];

    /** หมวดสถิติบนแบบฟอร์ม => คำที่ใช้จับคู่ชื่อประเภทการลา */
    public const STAT_GROUPS = [
        'sick' => ['ป่วย', 'ลาป่วย'],
        'personal' => ['กิจ', 'กิจส่วนตัว'],
        'maternity' => ['คลอด', 'คลอดบุตร'],
        'vacation' => ['พักผ่อน', 'ลาพักผ่อน'],
    ];

    private const STAT_COLUMNS = ['before' => 'ลามาแล้ว', 'current' => 'ลาครั้งนี้', 'total' => 'รวมเป็น'];

    /**
     * ช่องเฉพาะแบบฟอร์มที่แบบฟอร์มในระบบอ้างถึง — มีให้วางได้เสมอแม้ประเภทการลายังไม่ได้ตั้งช่องนั้น
     *
     * @var array<string, string>
     */
    private const KNOWN_EXTRA = [
        'wife_name' => 'ชื่อภริยา', 'childbirth_date' => 'วันที่ภริยาคลอดบุตร',
        'ordination_temple' => 'วัดที่อุปสมบท', 'ordination_temple_address' => 'ที่ตั้งวัดที่อุปสมบท', 'ordination_date' => 'วันกำหนดอุปสมบท',
        'retreat_temple' => 'วัดที่จำพรรษา', 'retreat_temple_address' => 'ที่ตั้งวัดที่จำพรรษา',
        'spouse_name' => 'ชื่อ-สกุลคู่สมรส', 'spouse_position' => 'ตำแหน่งของคู่สมรส', 'spouse_level' => 'ระดับของคู่สมรส',
        'spouse_affiliation' => 'สังกัดของคู่สมรส', 'spouse_country' => 'ประเทศที่คู่สมรสไปปฏิบัติงาน',
        'course' => 'วิชา/หลักสูตร', 'degree' => 'ขั้นปริญญา', 'institution' => 'สถานศึกษา/หน่วยงานที่ไป', 'country' => 'ประเทศ', 'funding' => 'ทุนที่ใช้',
        'destination' => 'สถานที่ไปราชการ', 'ref_doc_no' => 'ตามหนังสือที่',
        'summons_issuer' => 'หมายเรียกของ (หน่วยงาน)', 'summons_number' => 'เลขที่หมายเรียก', 'summons_date' => 'วันที่ของหมายเรียก',
        'report_place' => 'สถานที่ไปรายงานตัว',
        'organization_name' => 'ชื่อองค์การระหว่างประเทศ', 'assigned_position' => 'ตำแหน่งที่ไปปฏิบัติงาน',
        'injury_cause' => 'สาเหตุการบาดเจ็บ/เจ็บป่วยจากการปฏิบัติราชการ', 'injury_date' => 'วันที่บาดเจ็บ/เจ็บป่วย',
        'companion_1' => 'ผู้ร่วมเดินทาง 1', 'companion_2' => 'ผู้ร่วมเดินทาง 2', 'companion_3' => 'ผู้ร่วมเดินทาง 3',
        'companion_4' => 'ผู้ร่วมเดินทาง 4', 'companion_5' => 'ผู้ร่วมเดินทาง 5', 'companion_6' => 'ผู้ร่วมเดินทาง 6',
    ];

    /** ตัวเลือกของช่องแบบเลือกที่แบบฟอร์มในระบบใช้ติ๊กช่อง @var array<string, array<int, string>> */
    private const KNOWN_CHOICES = [
        'study_kind' => ['ศึกษา', 'ฝึกอบรม', 'ปฏิบัติการวิจัย', 'ดูงาน'],
        'call_kind' => ['เข้ารับการตรวจเลือก', 'เข้ารับการเตรียมพล'],
        'expense_type' => ['เบิกตามสิทธิ', 'ไม่เบิกค่าใช้จ่าย', 'เบิกเฉพาะค่ายานพาหนะ'],
    ];

    /**
     * ฟิลด์ทั้งหมด — รวมช่องเฉพาะแบบฟอร์มของทุกประเภทการลา
     *
     * @return array<int, array{code: string, label_th: string, group_th: string, data_type: string, path: ?string}>
     */
    public function definitions(): array
    {
        $rows = [
            ['organization.name', 'ชื่อสถานศึกษา', 'สถานศึกษา', 'TEXT', 'school_name'],
            ['organization.affiliation', 'สังกัดของสถานศึกษา', 'สถานศึกษา', 'TEXT', 'school_affiliation'],
            ['organization.line', 'ชื่อสถานศึกษาและสังกัด (บรรทัดเดียว)', 'สถานศึกษา', 'TEXT', 'organization_line'],
            ['organization.written_at', 'สถานที่เขียน (เขียนที่)', 'สถานศึกษา', 'TEXT', 'written_at'],
            ['organization.director_name', 'ชื่อผู้อำนวยการ', 'สถานศึกษา', 'TEXT', 'director_name'],
            ['organization.director_position', 'ตำแหน่งผู้อำนวยการ (บรรทัด "เรียน")', 'สถานศึกษา', 'TEXT', 'director_position'],

            ['leave.document_number', 'เลขที่ใบลา', 'เอกสาร', 'TEXT', 'doc_number'],
            ['leave.document_date', 'วันที่เขียนใบลา (เต็ม)', 'เอกสาร', 'DATE', 'doc_date_thai'],
            ['leave.document_day', 'วันที่เขียนใบลา — วัน', 'เอกสาร', 'TEXT', 'doc_day'],
            ['leave.document_month', 'วันที่เขียนใบลา — เดือน', 'เอกสาร', 'TEXT', 'doc_month'],
            ['leave.document_year', 'วันที่เขียนใบลา — พ.ศ.', 'เอกสาร', 'TEXT', 'doc_year'],
            ['leave.subject', 'เรื่อง', 'เอกสาร', 'TEXT', 'subject'],

            ['employee.full_name', 'ชื่อ-สกุลผู้ลา (พร้อมคำนำหน้า)', 'ผู้ลา', 'TEXT', 'user_name'],
            ['employee.position', 'ตำแหน่ง', 'ผู้ลา', 'TEXT', 'position'],
            ['employee.academic_rank', 'ระดับ/วิทยฐานะ', 'ผู้ลา', 'TEXT', 'level'],
            ['employee.division', 'แผนก/งาน', 'ผู้ลา', 'TEXT', 'division'],
            ['employee.department', 'สังกัดของผู้ลา', 'ผู้ลา', 'TEXT', 'department'],
            ['employee.affiliation', 'แผนก/งาน และสถานศึกษา (บรรทัด "สังกัด")', 'ผู้ลา', 'TEXT', 'affiliation_line'],
            ['employee.phone', 'หมายเลขโทรศัพท์', 'ผู้ลา', 'TEXT', 'phone'],
            ['employee.birth_date', 'วันเกิด', 'ผู้ลา', 'DATE', 'birth_date_thai'],
            ['employee.hired_date', 'วันเข้ารับราชการ', 'ผู้ลา', 'DATE', 'hired_date_thai'],
            ['leave.signature', 'ลายเซ็นผู้ลา', 'ผู้ลา', 'SIGNATURE', 'requester_signature'],

            ['leave.type', 'ประเภทการลา', 'การลาครั้งนี้', 'TEXT', 'leave_type'],
            ['leave.reason', 'เหตุผลการลา', 'การลาครั้งนี้', 'TEXT', 'reason'],
            ['leave.start_date', 'วันเริ่มลา (เต็ม)', 'การลาครั้งนี้', 'DATE', 'start_date_thai'],
            ['leave.start_day', 'วันเริ่มลา — วัน', 'การลาครั้งนี้', 'TEXT', 'start_day'],
            ['leave.start_month', 'วันเริ่มลา — เดือน', 'การลาครั้งนี้', 'TEXT', 'start_month'],
            ['leave.start_year', 'วันเริ่มลา — พ.ศ.', 'การลาครั้งนี้', 'TEXT', 'start_year'],
            ['leave.end_date', 'วันสิ้นสุดการลา (เต็ม)', 'การลาครั้งนี้', 'DATE', 'end_date_thai'],
            ['leave.end_day', 'วันสิ้นสุดการลา — วัน', 'การลาครั้งนี้', 'TEXT', 'end_day'],
            ['leave.end_month', 'วันสิ้นสุดการลา — เดือน', 'การลาครั้งนี้', 'TEXT', 'end_month'],
            ['leave.end_year', 'วันสิ้นสุดการลา — พ.ศ.', 'การลาครั้งนี้', 'TEXT', 'end_year'],
            ['leave.total_days', 'จำนวนวันลา', 'การลาครั้งนี้', 'NUMBER', 'total_days'],
            ['leave.duration_years', 'ระยะเวลาลา — ปี', 'การลาครั้งนี้', 'NUMBER', 'duration_years'],
            ['leave.duration_months', 'ระยะเวลาลา — เดือน', 'การลาครั้งนี้', 'NUMBER', 'duration_months'],
            ['leave.duration_days', 'ระยะเวลาลา — วัน', 'การลาครั้งนี้', 'NUMBER', 'duration_days'],
            ['leave.contact_address', 'ที่อยู่ติดต่อระหว่างลา', 'การลาครั้งนี้', 'TEXT', 'contact_address'],
            ['leave.substitute_name', 'ผู้ปฏิบัติงานแทน', 'การลาครั้งนี้', 'TEXT', null],
            ['leave.is_sick', 'ติ๊กช่อง "ป่วย"', 'การลาครั้งนี้', 'CHECKBOX', 'is_sick'],
            ['leave.is_personal', 'ติ๊กช่อง "กิจส่วนตัว"', 'การลาครั้งนี้', 'CHECKBOX', 'is_personal'],
            ['leave.is_maternity', 'ติ๊กช่อง "คลอดบุตร"', 'การลาครั้งนี้', 'CHECKBOX', 'is_maternity'],
            ['leave.has_substitute', 'ติ๊กช่อง "มอบหมายผู้ปฏิบัติงานแทน"', 'การลาครั้งนี้', 'CHECKBOX', null],
            ['leave.fiscal_year', 'ปีงบประมาณ (พ.ศ.)', 'การลาครั้งนี้', 'TEXT', 'fiscal_year'],

            ['last_leave.start_date', 'ลาครั้งสุดท้าย — ตั้งแต่วันที่', 'การลาครั้งก่อน', 'DATE', 'last_start_date_thai'],
            ['last_leave.end_date', 'ลาครั้งสุดท้าย — ถึงวันที่', 'การลาครั้งก่อน', 'DATE', 'last_end_date_thai'],
            ['last_leave.total_days', 'ลาครั้งสุดท้าย — จำนวนวัน', 'การลาครั้งก่อน', 'NUMBER', 'last_total_days'],
            ['history.done_before', 'ติ๊กช่อง "เคย" (อุปสมบท/ฮัจย์)', 'การลาครั้งก่อน', 'CHECKBOX', null],
            ['history.never_before', 'ติ๊กช่อง "ยังไม่เคย" (อุปสมบท/ฮัจย์)', 'การลาครั้งก่อน', 'CHECKBOX', null],

            ['vacation.accumulated', 'วันลาพักผ่อนสะสม', 'วันลาพักผ่อน', 'NUMBER', 'vacation_accumulated'],
            ['vacation.entitled', 'สิทธิลาพักผ่อนปีนี้', 'วันลาพักผ่อน', 'NUMBER', 'vacation_entitled'],
            ['vacation.total', 'รวมวันลาพักผ่อนที่ใช้ได้', 'วันลาพักผ่อน', 'NUMBER', 'vacation_total'],

            ['cancel.reason', 'เหตุผลการขอยกเลิก', 'การขอยกเลิกวันลา', 'TEXT', 'cancel_reason'],
            ['cancel.days', 'จำนวนวันที่ขอยกเลิก', 'การขอยกเลิกวันลา', 'NUMBER', 'cancel_days'],
            ['cancel.date', 'วันที่ยื่นคำขอยกเลิก', 'การขอยกเลิกวันลา', 'DATE', 'cancel_date_thai'],
            ['cancel.leave_text', 'ข้อความสรุปใบลาที่ขอยกเลิก', 'การขอยกเลิกวันลา', 'TEXT', 'cancelled_leave_text'],
        ];

        foreach (self::STAT_GROUPS as $group => [$keyword, $label]) {
            foreach (self::STAT_COLUMNS as $column => $columnLabel) {
                $rows[] = ["stats.{$group}.{$column}", "สถิติ{$label} — {$columnLabel}", 'สถิติการลาในปีงบประมาณ', 'TEXT', "stats.{$label}.{$column}"];
            }
        }

        foreach (self::ROLES as $role => $roleLabel) {
            $index = array_search($role, array_keys(self::ROLES), true);
            $rows[] = ["approval.{$role}.name", "{$roleLabel} — ชื่อ", 'ผู้ลงนาม', 'TEXT', "approverBlocks.{$index}.name"];
            $rows[] = ["approval.{$role}.position", "{$roleLabel} — ตำแหน่ง", 'ผู้ลงนาม', 'TEXT', "approverBlocks.{$index}.position"];
            $rows[] = ["approval.{$role}.acting_line", "{$roleLabel} — รักษาราชการแทน/ปฏิบัติราชการแทน", 'ผู้ลงนาม', 'TEXT', "approverBlocks.{$index}.acting_line"];
            $rows[] = ["approval.{$role}.comment", "{$roleLabel} — ความเห็น", 'ผู้ลงนาม', 'TEXT', "approverBlocks.{$index}.comment"];
            $rows[] = ["approval.{$role}.date", "{$roleLabel} — วันที่ลงนาม", 'ผู้ลงนาม', 'DATE', "approverBlocks.{$index}.date"];
            $rows[] = ["approval.{$role}.signature", "{$roleLabel} — ลายเซ็น", 'ผู้ลงนาม', 'SIGNATURE', "approverBlocks.{$index}.signature"];
            $rows[] = ["approval.{$role}.approved", "{$roleLabel} — ติ๊ก \"อนุญาต/เห็นชอบ\"", 'ผู้ลงนาม', 'CHECKBOX', null];
            $rows[] = ["approval.{$role}.rejected", "{$roleLabel} — ติ๊ก \"ไม่อนุญาต\"", 'ผู้ลงนาม', 'CHECKBOX', null];
        }

        foreach ($this->extraFields() as $key => $field) {
            $rows[] = ["extra.{$key}", $field['label'], 'ช่องเฉพาะแบบฟอร์ม', 'TEXT', "extra.{$key}"];

            foreach ($field['options'] as $option) {
                $rows[] = ["extra.{$key}.{$option}", "{$field['label']} — ติ๊ก \"{$option}\"", 'ช่องเฉพาะแบบฟอร์ม', 'CHECKBOX', null];
            }
        }

        return array_map(fn (array $row) => [
            'code' => $row[0], 'label_th' => $row[1], 'group_th' => $row[2], 'data_type' => $row[3], 'path' => $row[4],
        ], $rows);
    }

    /**
     * ค่าของทุกฟิลด์จากชุดตัวแปรของแบบฟอร์ม
     *
     * @param  array<string, mixed>  $data  LeaveDocumentFields::build() + approverBlocks + leave
     * @return array<string, mixed>
     */
    public function values(array $data): array
    {
        $values = [];

        foreach ($this->definitions() as $definition) {
            if ($definition['path'] !== null && ! str_starts_with($definition['path'], 'approverBlocks.') && ! str_starts_with($definition['path'], 'stats.')) {
                $values[$definition['code']] = data_get($data, $definition['path'], '');
            }
        }

        // ลายเซ็นผู้ลาใช้ path ตามที่เก็บ — Document Engine อ่านรูปผ่าน disk ของระบบเอง
        $values['leave.signature'] = (string) ($data['leave']->signature_path ?? '');

        $substitute = $data['leave']->substitute ?? null;
        $values['leave.substitute_name'] = $substitute ? ($substitute->full_name_with_title ?: (string) $substitute->name) : '';
        $values['leave.has_substitute'] = $substitute !== null;

        $hasLast = (bool) ($data['has_last_leave'] ?? false);
        foreach (['last_leave.start_date' => 'last_start_date_thai', 'last_leave.end_date' => 'last_end_date_thai', 'last_leave.total_days' => 'last_total_days'] as $code => $key) {
            $values[$code] = $hasLast ? ($data[$key] ?? '') : '-';
        }

        $doneBefore = $data['done_before'] ?? null;
        $values['history.done_before'] = $doneBefore === true;
        $values['history.never_before'] = $doneBefore === false;

        foreach (self::STAT_GROUPS as $group => [$keyword]) {
            $row = collect($data['stats'] ?? [])->first(fn ($row, $name) => str_contains((string) $name, $keyword)) ?? [];

            foreach (array_keys(self::STAT_COLUMNS) as $column) {
                $days = $row[$column] ?? '';
                $times = $row['times_'.$column] ?? '';
                $values["stats.{$group}.{$column}"] = $group === 'vacation' || $times === '' ? $days : "{$days} / {$times}";
            }
        }

        $blocks = array_values($data['approverBlocks'] ?? []);
        foreach (self::roles($blocks) as $role => $index) {
            $block = $index === null ? [] : $blocks[$index];
            $date = $block['date'] ?? null;

            $values["approval.{$role}.name"] = (string) ($block['name'] ?? '');
            $values["approval.{$role}.position"] = (string) ($block['position'] ?? '');
            $values["approval.{$role}.acting_line"] = (string) ($block['acting_line'] ?? '');
            $values["approval.{$role}.comment"] = (string) ($block['comment'] ?? '');
            $values["approval.{$role}.date"] = $date ? $date->format('d/m/').($date->year + 543) : '';
            $values["approval.{$role}.signature"] = (string) ($block['signature_path'] ?? '');
            $values["approval.{$role}.approved"] = ($block['status'] ?? '') === 'Approved';
            $values["approval.{$role}.rejected"] = ($block['status'] ?? '') === 'Rejected';
        }

        $extra = (array) ($data['extra'] ?? []);
        foreach ($this->extraFields() as $key => $field) {
            $value = (string) ($extra[$key] ?? '');
            $values["extra.{$key}"] = $value;

            foreach ($field['options'] as $option) {
                $values["extra.{$key}.{$option}"] = $value === $option;
            }
        }

        return $values;
    }

    /**
     * ค่าตัวอย่างของทุกฟิลด์ — ใช้พรีวิวบนหน้าออกแบบโดยไม่แตะข้อมูลจริงของผู้ใด
     *
     * @return array<string, mixed>
     */
    public function sampleValues(): array
    {
        $sample = app(LeaveDocumentFields::class)->sample();
        $leave = new Leave(['signature_path' => '']);
        $leave->setRelation('substitute', null);

        $values = $this->values($sample + [
            'leave' => $leave,
            'approverBlocks' => collect([
                ApprovalStep::CAPACITY_CHECKER => 'หัวหน้างานบุคลากร',
                ApprovalStep::CAPACITY_DEPUTY => 'รองผู้อำนวยการ',
                ApprovalStep::CAPACITY_DIRECTOR => 'ผู้อำนวยการ',
            ])->map(fn (string $position, string $capacity) => [
                'name' => 'ตัวอย่าง ผู้ลงนาม', 'position' => $position, 'comment' => 'เห็นควรอนุญาต',
                'date' => now(), 'signature_path' => null, 'status' => 'Approved', 'role_name' => '', 'capacity' => $capacity,
                'acting_line' => $capacity === ApprovalStep::CAPACITY_DIRECTOR ? 'ปฏิบัติราชการแทน ผู้อำนวยการ' : '',
            ])->values()->all(),
        ]);

        // ช่องเฉพาะแบบฟอร์มที่ยังไม่มีค่าตัวอย่าง ให้แสดงชื่อช่องแทน จะได้เห็นตำแหน่งบนพรีวิว
        foreach ($this->extraFields() as $key => $field) {
            if (($values["extra.{$key}"] ?? '') === '') {
                $values["extra.{$key}"] = $field['options'][0] ?? $field['label'];
            }
            foreach ($field['options'] as $index => $option) {
                $values["extra.{$key}.{$option}"] = $index === 0;
            }
        }

        return $values;
    }

    /**
     * ดัชนีของบล็อกผู้ลงนามตามบทบาทบนแบบฟอร์ม
     *
     *   authorizer = ขั้นสุดท้าย · commander = ขั้นก่อนสุดท้าย (มีขั้นเดียว = ขั้นนั้น)
     *   checker    = ขั้นของงานบุคลากร (ฐานะ checker) ที่ไม่ใช่สองบทบาทข้างต้น
     *                ถ้าไม่มีและมีตั้งแต่ 3 ขั้น ใช้ขั้นแรก
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array{checker: ?int, commander: ?int, authorizer: ?int}
     */
    public static function roles(array $blocks): array
    {
        $count = count($blocks);

        if ($count === 0) {
            return ['checker' => null, 'commander' => null, 'authorizer' => null];
        }

        $authorizer = $count - 1;
        $commander = $count >= 2 ? $count - 2 : $authorizer;

        $checker = null;
        foreach ($blocks as $index => $block) {
            if (self::isChecker($block) && $index !== $commander && $index !== $authorizer) {
                $checker = $index;
                break;
            }
        }
        if ($checker === null && $count >= 3) {
            $checker = 0;
        }

        return ['checker' => $checker, 'commander' => $commander, 'authorizer' => $authorizer];
    }

    /**
     * บล็อกของงานบุคลากร — จากฐานะผู้ลงนาม (ACCESS-GOV-01) ไม่ใช่ชื่อบทบาท
     * บล็อกที่สร้างก่อนมี `capacity` (เช่นค่าที่ส่งมาจากภายนอก) ยังอ่าน role_name ได้
     *
     * @param  array<string, mixed>  $block
     */
    public static function isChecker(array $block): bool
    {
        if (array_key_exists('capacity', $block)) {
            return $block['capacity'] === ApprovalStep::CAPACITY_CHECKER;
        }

        return ApprovalStep::capacityOf(null, (string) ($block['role_name'] ?? ''), null) === ApprovalStep::CAPACITY_CHECKER;
    }

    /**
     * ช่องเฉพาะแบบฟอร์มของทุกประเภทการลา + ช่องที่แบบฟอร์มในระบบอ้างถึง
     *
     * @return array<string, array{label: string, options: array<int, string>}>
     */
    public function extraFields(): array
    {
        $fields = [];

        foreach (self::KNOWN_EXTRA as $key => $label) {
            $fields[$key] = ['label' => $label, 'options' => []];
        }
        foreach (self::KNOWN_CHOICES as $key => $options) {
            $fields[$key] = ['label' => $fields[$key]['label'] ?? $key, 'options' => $options];
        }
        $fields['study_kind']['label'] = 'ลาไปเพื่อ (ศึกษา/ฝึกอบรม/วิจัย/ดูงาน)';
        $fields['expense_type']['label'] = 'การเบิกค่าใช้จ่ายเดินทางไปราชการ';
        $fields['call_kind']['label'] = 'รายงานลาเพื่อ (ตรวจเลือก/เตรียมพล)';

        foreach (LeaveType::query()->whereNotNull('extra_fields')->get(['id', 'name', 'extra_fields']) as $type) {
            foreach ((array) $type->extra_fields as $definition) {
                $key = (string) ($definition['key'] ?? '');

                // ระดับ/วิทยฐานะเป็นฟิลด์ของผู้ลา (employee.academic_rank) อยู่แล้ว
                if ($key === '' || $key === 'level' || ! preg_match('/^[a-z0-9_]{1,60}$/', $key)) {
                    continue;
                }

                $fields[$key] = [
                    'label' => $fields[$key]['label'] ?? (string) ($definition['label'] ?? $key),
                    'options' => array_values(array_unique(array_merge(
                        $fields[$key]['options'] ?? [],
                        ($definition['type'] ?? '') === 'select' ? Arr::wrap($definition['options'] ?? []) : [],
                    ))),
                ];
            }
        }

        return $fields;
    }
}
