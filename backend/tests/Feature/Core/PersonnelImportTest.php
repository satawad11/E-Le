<?php

namespace Tests\Feature\Core;

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\OrgUnit;
use App\Models\Personnel;
use App\Models\PersonnelProfile;
use App\Models\Position;
use App\Models\User;
use App\Services\Import\PersonnelImporter;
use App\Support\Spreadsheet\SheetReader;
use App\Support\Spreadsheet\SheetWriter;
use Database\Seeders\OrganizationBaselineSeeder;
use Database\Seeders\OrgUnitTypeSeeder;
use Database\Seeders\PersonnelTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R03-5 / REQ-PER-06 — นำเข้า Excel พร้อม dry-run และรายงานข้อผิดพลาดรายแถว
 */
class PersonnelImportTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        (new OrgUnitTypeSeeder)->run();
        (new OrganizationBaselineSeeder)->run();
        (new PersonnelTypeSeeder)->run();
        $this->dir = sys_get_temp_dir().'/r03-import-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array<string, array<int, array<int, string>>> */
    private function validWorkbook(): array
    {
        return [
            'org_units' => [
                ['code', 'name', 'type_code', 'parent_code', 'effective_from', 'sort_order'],
                // หน่วยลูกอยู่ก่อนหน่วยแม่ — ต้องนำเข้าได้
                ['PRG-AUTO', 'สาขางานยานยนต์', 'program', 'DEP-AUTO', '2026-05-16', '1'],
                ['DEP-AUTO', 'แผนกวิชาช่างยนต์', 'department', 'DIV-ACADEMIC', '46158', '1'], // serial ของ 2026-05-16
                ['SEC-HR', 'งานบุคลากร', 'section', 'DIV-RESOURCES', '2026-05-16', '2'],
            ],
            'positions' => [
                ['code', 'name', 'kind', 'group_code', 'group_name', 'org_unit_type_code', 'sort_order'],
                ['TEACHER', 'ครู', 'line', 'TEACHER', 'ครู', '', '1'],
                ['DEPT-HEAD', 'หัวหน้าแผนกวิชา', 'management', 'EXECUTIVE', 'ผู้บริหาร', 'department', '2'],
                ['DEPUTY', 'รองผู้อำนวยการ', 'management', 'EXECUTIVE', 'ผู้บริหาร', 'division', '3'],
            ],
            'personnel' => [
                ['personnel_code', 'title', 'first_name', 'last_name', 'personnel_type_code', 'status', 'started_at', 'email_work', 'national_id'],
                ['T001', 'นาย', 'สมชาย', 'ใจดี', 'civil_teacher', 'active', '2015-05-16', 'somchai@college.test', '1103700012346'],
                ['T002', 'นาง', 'สมหญิง', 'รักเรียน', 'contract_teacher', '', '2024-05-16', '', ''],
            ],
            'assignments' => [
                ['personnel_code', 'kind', 'org_unit_code', 'position_code', 'acting_for_org_unit_code', 'order_number', 'order_date', 'effective_from', 'effective_to'],
                ['T001', 'primary', 'DEP-AUTO', 'TEACHER', '', '', '', '2026-05-16', ''],
                ['T001', 'management', 'DEP-AUTO', 'DEPT-HEAD', '', '12/2569', '2026-05-01', '2026-05-16', ''],
                ['T002', 'primary', 'PRG-AUTO', 'TEACHER', '', '', '', '2026-05-16', ''],
            ],
        ];
    }

    private function xlsx(array $sheets): string
    {
        $path = $this->dir.'/import.xlsx';
        app(SheetWriter::class)->write($path, $sheets);

        return $path;
    }

    public function test_dry_run_reports_no_errors_and_saves_nothing(): void
    {
        $result = app(PersonnelImporter::class)->importFile($this->xlsx($this->validWorkbook()));

        $this->assertTrue($result['dry_run']);
        $this->assertFalse($result['committed']);
        $this->assertSame(0, $result['error_count'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, $result['sheets']['org_units']['created']);
        $this->assertSame(3, $result['sheets']['assignments']['created']);

        $this->assertSame(5, OrgUnit::count());
        $this->assertSame(0, Personnel::count());
        $this->assertSame(0, Position::count());
    }

    public function test_commit_saves_everything_through_the_domain_rules(): void
    {
        $result = app(PersonnelImporter::class)->importFile($this->xlsx($this->validWorkbook()), commit: true);

        $this->assertTrue($result['committed']);
        $auto = OrgUnit::where('code', 'DEP-AUTO')->firstOrFail();
        $program = OrgUnit::where('code', 'PRG-AUTO')->firstOrFail();
        $this->assertSame('2026-05-16', $auto->effective_from->toDateString());
        $this->assertSame($auto->path.$program->id.'/', $program->path);

        $somchai = Personnel::where('personnel_code', 'T001')->firstOrFail();
        $this->assertSame(PersonnelProfile::hashNationalId('1103700012346'), $somchai->profile->national_id_hash);
        $this->assertSame(['management', 'primary'], Assignment::where('personnel_id', $somchai->id)->orderBy('kind')->pluck('kind')->all());
        $this->assertSame('active', Personnel::where('personnel_code', 'T002')->value('status'));

        // นำเข้าซ้ำ: ข้ามรายการที่มีอยู่แล้ว ไม่เขียนทับ ไม่สร้างซ้ำ
        $again = app(PersonnelImporter::class)->importFile($this->xlsx($this->validWorkbook()), commit: true);
        $this->assertSame(0, $again['error_count']);
        $this->assertSame(2, $again['sheets']['personnel']['skipped']);
        $this->assertSame(0, $again['sheets']['personnel']['created']);
        $this->assertSame(3, Assignment::count());
    }

    public function test_errors_are_reported_per_row_and_block_the_commit(): void
    {
        $sheets = $this->validWorkbook();
        $sheets['org_units'][] = ['BAD-TYPE', 'หน่วยผิด', 'nope', 'DIV-ACADEMIC', '2026-05-16', ''];          // แถว 5
        $sheets['org_units'][] = ['ORPHAN', 'ไม่มีแม่', 'section', 'NOT-THERE', '2026-05-16', ''];            // แถว 6
        $sheets['personnel'][] = ['T003', 'นาย', 'เลขผิด', 'ทดสอบ', 'civil_teacher', '', '', '', '1103700012345']; // แถว 4
        $sheets['assignments'][] = ['T002', 'primary', 'SEC-HR', 'TEACHER', '', '', '', '2026-06-01', ''];   // แถว 5 primary ซ้อน
        $sheets['assignments'][] = ['T001', 'management', 'SEC-HR', 'DEPT-HEAD', '', '', '', '2026-05-16', '']; // แถว 6 ไม่มีคำสั่ง + ตำแหน่งไม่ตรงประเภท

        $result = app(PersonnelImporter::class)->importFile($this->xlsx($sheets), commit: true);

        $this->assertFalse($result['committed']);
        $this->assertSame(0, Personnel::count(), 'มีข้อผิดพลาดแม้แถวเดียวต้องไม่บันทึกอะไรเลย');

        $rows = fn (string $sheet) => array_column($result['sheets'][$sheet]['errors'], 'row');
        $this->assertContains(5, $rows('org_units'));
        $this->assertContains(6, $rows('org_units'));
        $this->assertSame([4], $rows('personnel'));
        $this->assertContains(5, $rows('assignments'));
        $this->assertContains(6, $rows('assignments'));
        $this->assertStringContainsString('หลักตรวจสอบ', $result['sheets']['personnel']['errors'][0]['message']);
    }

    public function test_import_creates_no_accounts_never_reads_passwords_and_audits_the_commit(): void
    {
        $sheets = $this->validWorkbook();
        $sheets['personnel'][0][] = 'password';
        foreach (array_keys($sheets['personnel']) as $i) {
            if ($i > 0) {
                $sheets['personnel'][$i][] = 'Secret#123456';
            }
        }

        $result = app(PersonnelImporter::class)->importFile($this->xlsx($sheets), commit: true);

        $this->assertTrue($result['committed']);
        $this->assertSame(0, User::count(), 'บัญชีเกิดจาก JML (R-05) ไม่ใช่จากไฟล์นำเข้า');
        $this->assertTrue(AuditLog::where('action', 'personnel.import.committed')->exists());
    }

    public function test_duplicate_national_id_earlier_in_the_same_file_is_rejected(): void
    {
        $sheets = $this->validWorkbook();
        $sheets['personnel'][] = ['T003', 'นาย', 'ซ้ำ', 'ในไฟล์', 'civil_teacher', '', '', '', '1103700012346']; // แถว 4

        $result = app(PersonnelImporter::class)->importFile($this->xlsx($sheets));

        $this->assertSame([4], array_column($result['sheets']['personnel']['errors'], 'row'));
        $this->assertStringContainsString('มีในระบบแล้ว', $result['sheets']['personnel']['errors'][0]['message']);
    }

    public function test_missing_required_columns_and_unknown_sheets_are_reported(): void
    {
        $result = app(PersonnelImporter::class)->import([
            'personnel' => [2 => ['personnel_code' => 'T9', 'first_name' => 'ก']],
            'staff' => [2 => ['x' => '1']],
        ]);

        $this->assertStringContainsString('last_name', $result['sheets']['personnel']['errors'][0]['message']);
        $this->assertStringContainsString('staff', $result['sheets']['staff']['errors'][0]['message']);
    }

    public function test_csv_input_and_import_command(): void
    {
        $csv = $this->dir.'/personnel.csv';
        file_put_contents($csv, "\xEF\xBB\xBFpersonnel_code,first_name,last_name,personnel_type_code\nT010,สมใจ,นำเข้า,permanent_employee\n");

        $this->artisan('personnel:import', ['file' => $csv, '--sheet' => 'personnel'])->assertSuccessful();
        $this->assertSame(0, Personnel::count(), 'ค่าเริ่มต้นเป็น dry-run');

        $this->artisan('personnel:import', ['file' => $csv, '--sheet' => 'personnel', '--commit' => true])->assertSuccessful();
        $this->assertSame('สมใจ', Personnel::where('personnel_code', 'T010')->value('first_name'));

        $bad = $this->dir.'/bad.csv';
        file_put_contents($bad, "personnel_code,first_name,last_name,personnel_type_code\nT011,ก,ข,unknown_type\n");
        $this->artisan('personnel:import', ['file' => $bad, '--sheet' => 'personnel', '--commit' => true])->assertFailed();
    }

    public function test_template_command_writes_all_sheets_with_headers(): void
    {
        $path = $this->dir.'/template.xlsx';
        $this->artisan('personnel:import-template', ['path' => $path])->assertSuccessful();

        $sheets = app(SheetReader::class)->read($path);
        $this->assertSame(PersonnelImporter::SHEETS, array_keys($sheets));
        $this->assertSame([], $sheets['personnel'], 'มีเฉพาะหัวคอลัมน์');
    }

    public function test_reader_roundtrips_thai_text_and_converts_excel_dates(): void
    {
        $path = $this->xlsx(['แผ่นทดสอบ' => [['ชื่อ', 'date'], ['วิทยาลัยเทคนิคดอนเมือง & <ทดสอบ>', '46158'], ['', ''], ['แถวถัดไป', '2026-05-16']]]);

        $rows = app(SheetReader::class)->read($path)['แผ่นทดสอบ'];

        $this->assertSame([2, 4], array_keys($rows), 'แถวว่างถูกข้ามแต่เลขแถวคงตามไฟล์');
        $this->assertSame('วิทยาลัยเทคนิคดอนเมือง & <ทดสอบ>', $rows[2]['ชื่อ']);
        $this->assertSame('2026-05-16', SheetReader::excelSerialToDate($rows[2]['date']));
        $this->assertSame('2026-05-16', SheetReader::excelSerialToDate($rows[4]['date']));
    }

    public function test_thai_national_id_checksum(): void
    {
        $this->assertTrue(PersonnelImporter::validNationalId('1103700012346'));
        $this->assertFalse(PersonnelImporter::validNationalId('1103700012345'));
        $this->assertFalse(PersonnelImporter::validNationalId('110370001234'));
    }
}
