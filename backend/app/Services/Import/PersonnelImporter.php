<?php

namespace App\Services\Import;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\Personnel;
use App\Models\PersonnelProfile;
use App\Models\PersonnelType;
use App\Models\Position;
use App\Models\PositionGroup;
use App\Services\AuditLogger;
use App\Services\Org\OrgTree;
use App\Services\Personnel\AssignmentService;
use App\Support\Spreadsheet\SheetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * นำเข้าหน่วยงาน ตำแหน่ง บุคลากร และการดำรงตำแหน่งจาก Excel (R03-5 · REQ-PER-06)
 *
 * - แผ่นงาน (ลำดับการประมวลผล): org_units → positions → personnel → assignments · แผ่นที่ไม่มีข้ามได้
 * - ค่าเริ่มต้นเป็น dry-run: ประมวลผลจริงทุกแถวใน transaction แล้ว rollback เพื่อรายงานข้อผิดพลาดรายแถว
 * - commit ได้เมื่อ **ไม่มีข้อผิดพลาดเลย** (ทั้งไฟล์หรือไม่บันทึกเลย) — กันข้อมูลครึ่ง ๆ กลาง ๆ
 * - แถวที่มีรหัสอยู่แล้วในระบบถูกข้าม ไม่เขียนทับ (การแก้ข้อมูลเดิมทำผ่านหน้าจอ/คำขอแก้ไข)
 * - ใช้ OrgTree / AssignmentService จึงได้กฎเดียวกับการบันทึกผ่านหน้าจอทุกข้อ
 */
class PersonnelImporter
{
    public const SHEETS = ['org_units', 'positions', 'personnel', 'assignments'];

    public const COLUMNS = [
        'org_units' => ['code', 'name', 'type_code', 'parent_code', 'effective_from', 'sort_order'],
        'positions' => ['code', 'name', 'kind', 'group_code', 'group_name', 'org_unit_type_code', 'sort_order'],
        'personnel' => ['personnel_code', 'title', 'first_name', 'last_name', 'first_name_en', 'last_name_en', 'personnel_type_code',
            'academic_standing', 'status', 'started_at', 'email_work', 'phone_mobile', 'birth_date', 'national_id'],
        'assignments' => ['personnel_code', 'kind', 'org_unit_code', 'position_code', 'acting_for_org_unit_code',
            'order_number', 'order_date', 'effective_from', 'effective_to'],
    ];

    private const REQUIRED_COLUMNS = [
        'org_units' => ['code', 'name', 'type_code', 'effective_from'],
        'positions' => ['code', 'name', 'kind'],
        'personnel' => ['personnel_code', 'first_name', 'last_name', 'personnel_type_code'],
        'assignments' => ['personnel_code', 'kind', 'effective_from'],
    ];

    private const DATE_COLUMNS = ['effective_from', 'effective_to', 'started_at', 'birth_date', 'order_date'];

    /** @var array<string, array{total: int, created: int, skipped: int, errors: array<int, array{row: int, message: string}>}> */
    private array $report = [];

    public function __construct(
        private readonly SheetReader $reader,
        private readonly OrgTree $tree,
        private readonly AssignmentService $assignments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{dry_run: bool, committed: bool, error_count: int, sheets: array<string, mixed>}
     */
    public function importFile(string $path, bool $commit = false, ?int $actorId = null): array
    {
        return $this->import($this->reader->read($path), $commit, $actorId);
    }

    /**
     * @param  array<string, array<int, array<string, string>>>  $sheets
     * @return array{dry_run: bool, committed: bool, error_count: int, sheets: array<string, mixed>}
     */
    public function import(array $sheets, bool $commit = false, ?int $actorId = null): array
    {
        $this->report = [];
        $sheets = array_change_key_case($sheets, CASE_LOWER);

        foreach (array_diff(array_keys($sheets), self::SHEETS) as $unknown) {
            $this->report[$unknown] = ['total' => 0, 'created' => 0, 'skipped' => 0, 'errors' => [['row' => 0, 'message' => "ไม่รู้จักแผ่นงาน \"{$unknown}\" (ใช้ได้: ".implode(', ', self::SHEETS).')']]];
        }

        DB::beginTransaction();

        try {
            foreach (self::SHEETS as $name) {
                if (! isset($sheets[$name])) {
                    continue;
                }
                $rows = $this->normaliseDates($sheets[$name]);
                $this->report[$name] = ['total' => count($rows), 'created' => 0, 'skipped' => 0, 'errors' => []];

                $missing = array_diff(self::REQUIRED_COLUMNS[$name], array_keys(reset($rows) ?: []));
                if ($rows !== [] && $missing !== []) {
                    $this->error($name, 0, 'ไม่พบคอลัมน์ที่จำเป็น: '.implode(', ', $missing));

                    continue;
                }

                match ($name) {
                    'org_units' => $this->importOrgUnits($rows, $actorId),
                    'positions' => $this->eachRow($name, $rows, fn (array $r) => $this->importPosition($r)),
                    'personnel' => $this->eachRow($name, $rows, fn (array $r) => $this->importPersonnel($r)),
                    'assignments' => $this->eachRow($name, $rows, fn (array $r) => $this->importAssignment($r, $actorId)),
                };
            }

            $errorCount = array_sum(array_map(fn ($s) => count($s['errors']), $this->report));
            $committed = $commit && $errorCount === 0;

            if ($committed) {
                $this->audit->log('personnel.import.committed', 'personnel_import', null, null, $this->summary(), null, $actorId);
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return ['dry_run' => ! $commit, 'committed' => $committed, 'error_count' => $errorCount, 'sheets' => $this->report];
    }

    /** @param  array<int, array<string, string>>  $rows */
    private function importOrgUnits(array $rows, ?int $actorId): void
    {
        // หน่วยลูกอาจอยู่ก่อนหน่วยแม่ในไฟล์ — ทำหลายรอบจนไม่มีแถวใดสร้างเพิ่มได้
        $pending = $rows;
        do {
            $progress = false;
            foreach ($pending as $rowNumber => $row) {
                $parentCode = $row['parent_code'] ?? '';
                if ($parentCode !== '' && ! OrgUnit::where('code', $parentCode)->exists() && $this->codeInRows($pending, $parentCode, $rowNumber)) {
                    continue; // รอหน่วยแม่ในรอบถัดไป
                }
                unset($pending[$rowNumber]);
                $progress = true;
                $this->row('org_units', $rowNumber, fn () => $this->importOrgUnit($row, $actorId));
            }
        } while ($progress && $pending !== []);

        foreach ($pending as $rowNumber => $row) {
            $this->error('org_units', $rowNumber, "หน่วยแม่ \"{$row['parent_code']}\" อ้างกันเป็นวง หรือสร้างไม่ได้");
        }
    }

    /** @param  array<string, string>  $row */
    private function importOrgUnit(array $row, ?int $actorId): string
    {
        $this->validate($row, [
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:255'],
            'type_code' => ['required', 'exists:org_unit_types,code'],
            'effective_from' => ['required', 'date'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        if (OrgUnit::where('code', $row['code'])->exists()) {
            return 'skipped';
        }

        $parent = null;
        if (($row['parent_code'] ?? '') !== '') {
            $parent = OrgUnit::where('code', $row['parent_code'])->first()
                ?? throw ValidationException::withMessages(['parent_code' => "ไม่พบหน่วยแม่ \"{$row['parent_code']}\""]);
        }

        $this->tree->create([
            'code' => $row['code'],
            'name' => $row['name'],
            'org_unit_type_id' => OrgUnitType::where('code', $row['type_code'])->value('id'),
            'effective_from' => $row['effective_from'],
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ], $parent, $actorId);

        return 'created';
    }

    /** @param  array<string, string>  $row */
    private function importPosition(array $row): string
    {
        $this->validate($row, [
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'in:line,management'],
            'org_unit_type_code' => ['nullable', 'required_if:kind,management', 'exists:org_unit_types,code'],
            'group_code' => ['nullable', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        if (Position::where('code', $row['code'])->exists()) {
            return 'skipped';
        }

        $typeId = $row['kind'] === 'management' ? OrgUnitType::where('code', $row['org_unit_type_code'])->value('id') : null;
        if ($typeId !== null && Position::where('org_unit_type_id', $typeId)->exists()) {
            throw ValidationException::withMessages(['org_unit_type_code' => 'ประเภทหน่วยนี้มีตำแหน่งบริหารอยู่แล้ว (ประเภทละหนึ่งตำแหน่ง)']);
        }

        $groupId = null;
        if (($row['group_code'] ?? '') !== '') {
            $groupId = PositionGroup::firstOrCreate(['code' => $row['group_code']], ['name' => $row['group_name'] ?: $row['group_code']])->id;
        }

        Position::create([
            'code' => $row['code'],
            'name' => $row['name'],
            'kind' => $row['kind'],
            'position_group_id' => $groupId,
            'org_unit_type_id' => $typeId,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ]);

        return 'created';
    }

    /** @param  array<string, string>  $row */
    private function importPersonnel(array $row): string
    {
        $this->validate($row, [
            'personnel_code' => ['required', 'string', 'max:40'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'personnel_type_code' => ['required', 'exists:personnel_types,code'],
            'status' => ['nullable', 'in:'.implode(',', Personnel::STATUSES)],
            'started_at' => ['nullable', 'date'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'email_work' => ['nullable', 'email'],
            'national_id' => ['nullable', 'digits:13'],
        ]);

        if (Personnel::where('personnel_code', $row['personnel_code'])->exists()) {
            return 'skipped';
        }

        if (($row['national_id'] ?? '') !== '') {
            if (! self::validNationalId($row['national_id'])) {
                throw ValidationException::withMessages(['national_id' => 'เลขประจำตัวประชาชนไม่ถูกต้อง (หลักตรวจสอบไม่ตรง)']);
            }
            if (PersonnelProfile::where('national_id_hash', PersonnelProfile::hashNationalId($row['national_id']))->exists()) {
                throw ValidationException::withMessages(['national_id' => 'เลขประจำตัวประชาชนนี้มีในระบบแล้ว']);
            }
        }

        $personnel = Personnel::create([
            'personnel_code' => $row['personnel_code'],
            'title' => $row['title'] ?? null,
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'first_name_en' => ($row['first_name_en'] ?? '') ?: null,
            'last_name_en' => ($row['last_name_en'] ?? '') ?: null,
            'personnel_type_id' => PersonnelType::where('code', $row['personnel_type_code'])->value('id'),
            'academic_standing' => ($row['academic_standing'] ?? '') ?: null,
            'status' => ($row['status'] ?? '') ?: 'active',
            'started_at' => ($row['started_at'] ?? '') ?: null,
        ]);

        $profile = array_filter([
            'email_work' => $row['email_work'] ?? null,
            'phone_mobile' => $row['phone_mobile'] ?? null,
            'birth_date' => $row['birth_date'] ?? null,
            'national_id' => $row['national_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        PersonnelProfile::create(['personnel_id' => $personnel->id] + $profile);

        return 'created';
    }

    /** @param  array<string, string>  $row */
    private function importAssignment(array $row, ?int $actorId): string
    {
        $this->validate($row, [
            'personnel_code' => ['required', 'exists:personnel,personnel_code'],
            'kind' => ['required', 'in:'.implode(',', Assignment::KINDS)],
            'org_unit_code' => ['required_unless:kind,acting', 'nullable', 'exists:org_units,code'],
            'position_code' => ['required_unless:kind,acting', 'nullable', 'exists:positions,code'],
            'acting_for_org_unit_code' => ['required_if:kind,acting', 'nullable', 'exists:org_units,code'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date'],
            'order_date' => ['nullable', 'date'],
        ]);

        $personnel = Personnel::where('personnel_code', $row['personnel_code'])->firstOrFail();
        $data = [
            'kind' => $row['kind'],
            'effective_from' => $row['effective_from'],
            'effective_to' => ($row['effective_to'] ?? '') ?: null,
            'order_number' => ($row['order_number'] ?? '') ?: null,
            'order_date' => ($row['order_date'] ?? '') ?: null,
        ];

        if ($row['kind'] === Assignment::KIND_ACTING) {
            $unit = OrgUnit::where('code', $row['acting_for_org_unit_code'])->firstOrFail();
            $head = Assignment::where('org_unit_id', $unit->id)->where('kind', Assignment::KIND_MANAGEMENT)
                ->effectiveOn($row['effective_from'])->first()
                ?? Assignment::where('org_unit_id', $unit->id)->where('kind', Assignment::KIND_MANAGEMENT)
                    ->orderByDesc('effective_from')->first()
                ?? throw ValidationException::withMessages(['acting_for_org_unit_code' => "หน่วย \"{$unit->name}\" ยังไม่มีตำแหน่งบริหารให้รักษาการ"]);
            $data['acting_for_assignment_id'] = $head->id;
        } else {
            $data['org_unit_id'] = OrgUnit::where('code', $row['org_unit_code'])->value('id');
            $data['position_id'] = Position::where('code', $row['position_code'])->value('id');

            $duplicate = Assignment::where('personnel_id', $personnel->id)->where('kind', $row['kind'])
                ->where('org_unit_id', $data['org_unit_id'])->where('position_id', $data['position_id'])
                ->whereDate('effective_from', $row['effective_from'])->exists();
            if ($duplicate) {
                return 'skipped';
            }
        }

        $this->assignments->add($personnel, $data, $actorId, initialImport: true);

        return 'created';
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @param  callable(array<string, string>): string  $handler
     */
    private function eachRow(string $sheet, array $rows, callable $handler): void
    {
        foreach ($rows as $rowNumber => $row) {
            $this->row($sheet, $rowNumber, fn () => $handler($row));
        }
    }

    /** แต่ละแถวอยู่ใน savepoint ของตนเอง แถวที่ผิดไม่ทิ้งผลค้างไว้ */
    private function row(string $sheet, int $rowNumber, callable $handler): void
    {
        try {
            $result = DB::transaction(fn () => $handler());
            $this->report[$sheet][$result === 'skipped' ? 'skipped' : 'created']++;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->error($sheet, $rowNumber, "{$field}: {$message}");
                }
            }
        }
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, mixed>  $rules
     */
    private function validate(array $row, array $rules): void
    {
        Validator::make(array_map(fn ($v) => $v === '' ? null : $v, $row), $rules)->validate();
    }

    private function error(string $sheet, int $row, string $message): void
    {
        $this->report[$sheet]['errors'][] = ['row' => $row, 'message' => $message];
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, string>>
     */
    private function normaliseDates(array $rows): array
    {
        foreach ($rows as $n => $row) {
            foreach (self::DATE_COLUMNS as $column) {
                if (isset($row[$column])) {
                    $rows[$n][$column] = (string) SheetReader::excelSerialToDate($row[$column]);
                }
            }
        }

        return $rows;
    }

    /** @param  array<int, array<string, string>>  $rows */
    private function codeInRows(array $rows, string $code, int $except): bool
    {
        foreach ($rows as $n => $row) {
            if ($n !== $except && ($row['code'] ?? null) === $code) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array{total: int, created: int, skipped: int}> */
    private function summary(): array
    {
        return array_map(fn ($s) => ['total' => $s['total'], 'created' => $s['created'], 'skipped' => $s['skipped']], $this->report);
    }

    public static function validNationalId(string $id): bool
    {
        if (! preg_match('/^\d{13}$/', $id)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $id[$i] * (13 - $i);
        }

        return (11 - $sum % 11) % 10 === (int) $id[12];
    }
}
