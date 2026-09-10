<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\PersonnelAssignment;
use App\Models\Position;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\DocumentVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * INT-03 — โครงข้อมูลของสารบรรณ และการอ้างอิงกลับไปยังระบบกลาง
 *
 * ยืนยันสามเรื่องที่ `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md` บังคับไว้:
 *
 *   §5  ทุกการอ้างถึงคน/หน่วยงาน/ตำแหน่ง ต้องเป็น id ของระบบกลาง ไม่ใช่ทะเบียนของตัวเอง
 *   §8  หนังสือเป็น entity หลัก · PDF เป็นภาพแทนของแต่ละเวอร์ชัน · ต้นฉบับห้ามถูกเขียนทับ
 *   §13 ต้องตอบได้ว่าใครดำรงตำแหน่งใด ซึ่งต้องเทียบด้วย id ไม่ใช่จับคู่ข้อความ
 */
class SarabanDataModelTest extends TestCase
{
    use RefreshDatabase;

    private function orgUnit(): OrgUnit
    {
        return OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-'.Str::random(6),
            'name' => 'ฝ่ายทดสอบ',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);
    }

    private function documentType(): DocumentType
    {
        return DocumentType::create([
            // INT-05 — migration ลงประเภทหนังสือมาตรฐานไว้แล้ว (`external`, `internal`, …)
            // เทสต์จึงต้องใช้รหัสของตัวเองเพื่อไม่ชนกับข้อมูลอ้างอิงจริง
            'code' => 'data-model-test',
            'name' => 'หนังสือภายนอก',
            'abbreviation' => 'ภน.',
            'category' => DocumentType::CATEGORY_INCOMING,
        ]);
    }

    private function document(?User $creator = null, ?OrgUnit $unit = null): Document
    {
        return Document::create([
            'document_type_id' => $this->documentType()->id,
            'direction' => Document::DIRECTION_INCOMING,
            'subject' => 'ขอเชิญประชุมคณะกรรมการ',
            'owner_org_unit_id' => ($unit ?? $this->orgUnit())->id,
            'created_by' => ($creator ?? User::factory()->create())->id,
        ]);
    }

    // ── §5 · การอ้างอิงระบบกลาง ───────────────────────────────────────────────

    /**
     * ตารางของสารบรรณต้องไม่มี `department_id` เลย
     *
     * `departments` เป็นหน่วยงานระดับเดียว ส่วนงานสารบรรณต้องเดินเรื่องข้ามหลายชั้น
     * จึงต้องอ้าง `org_units` ที่เป็นต้นไม้และมี effective dating
     * (สัญญา INT-01 §1.3 · บังคับซ้ำอีกชั้นใน `SarabanCoreContractTest`)
     */
    public function test_no_saraban_table_references_the_single_level_department(): void
    {
        $offenders = [];

        foreach ($this->sarabanTables() as $table) {
            if (Schema::hasColumn($table, 'org_unit_id')) {
                $offenders[] = $table;
            }
        }

        $this->assertSame([], $offenders, 'ตารางสารบรรณต้องอ้าง org_unit_id ไม่ใช่ department_id: '.implode(', ', $offenders));
    }

    public function test_documents_reference_core_users_and_org_units(): void
    {
        $creator = User::factory()->create();
        $unit = $this->orgUnit();

        $document = $this->document($creator, $unit);

        $this->assertTrue($document->ownerOrgUnit->is($unit));
        $this->assertTrue($document->creator->is($creator));

        $this->assertDatabaseHas('saraban_documents', [
            'id' => $document->id,
            'owner_org_unit_id' => $unit->id,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * ลบหน่วยงานหรือผู้ใช้ที่ยังมีหนังสืออ้างอยู่ไม่ได้
     *
     * หนังสือราชการเป็นหลักฐาน การปล่อยให้ผู้อ้างอิงหายไปจะทำให้ประวัติขาด
     * จึงตั้ง `restrictOnDelete` ไว้ ไม่ใช่ `cascade` หรือ `nullOnDelete`
     */
    public function test_core_records_cannot_be_deleted_while_a_document_still_points_at_them(): void
    {
        $unit = $this->orgUnit();
        $creator = User::factory()->create();
        $this->document($creator, $unit);

        $this->expectException(QueryException::class);

        DB::table('org_units')->where('id', $unit->id)->delete();
    }

    // ── §8 · หนังสือเป็น entity หลัก และต้นฉบับห้ามถูกเขียนทับ ────────────────

    public function test_a_document_keeps_every_version_and_never_overwrites_the_original(): void
    {
        $document = $this->document();
        $author = User::factory()->create();

        $original = DocumentVersion::create([
            'document_id' => $document->id,
            'version_no' => 1,
            'stage' => DocumentVersion::STAGE_ORIGINAL,
            'pdf_file_path' => 'saraban/documents/original.pdf',
            'file_hash' => hash('sha256', 'original'),
            'created_by' => $author->id,
        ]);

        $revised = DocumentVersion::create([
            'document_id' => $document->id,
            'version_no' => 2,
            'stage' => DocumentVersion::STAGE_REVISED,
            'pdf_file_path' => 'saraban/documents/revised.pdf',
            'file_hash' => hash('sha256', 'revised'),
            'created_by' => $author->id,
        ]);

        $document->update(['current_version_id' => $revised->id]);
        $document->refresh();

        // ฉบับปัจจุบันเปลี่ยนไป แต่ต้นฉบับยังอยู่ครบและเข้าถึงได้
        $this->assertTrue($document->currentVersion->is($revised));
        $this->assertTrue($document->originalVersion->is($original));
        $this->assertSame([1, 2], $document->versions->pluck('version_no')->all());

        $original->refresh();
        $this->assertSame(hash('sha256', 'original'), $original->file_hash);
        $this->assertSame('saraban/documents/original.pdf', $original->pdf_file_path);
    }

    public function test_a_document_cannot_have_two_versions_with_the_same_number(): void
    {
        $document = $this->document();
        $author = User::factory()->create();

        DocumentVersion::create([
            'document_id' => $document->id,
            'version_no' => 1,
            'created_by' => $author->id,
        ]);

        $this->expectException(QueryException::class);

        DocumentVersion::create([
            'document_id' => $document->id,
            'version_no' => 1,
            'created_by' => $author->id,
        ]);
    }

    /**
     * ไฟล์ PDF ต้องอยู่บน disk ส่วนตัวเท่านั้น
     *
     * เก็บเป็น path เปล่า ๆ ไม่ใช่ URL — การเข้าถึงต้องผ่าน `SecureFileController`
     * ตามสัญญา INT-01 §1.12 ถ้าเก็บเป็น URL เต็มจะเปิดช่องให้มีคนชี้ตรงไปที่ไฟล์
     */
    public function test_version_files_are_stored_as_private_paths_not_public_urls(): void
    {
        $document = $this->document();

        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'version_no' => 1,
            'pdf_file_path' => 'saraban/documents/1/original.pdf',
            'created_by' => User::factory()->create()->id,
        ]);

        $this->assertStringStartsNotWith('http', $version->pdf_file_path);
        $this->assertStringNotContainsString('/storage/', $version->pdf_file_path);
    }

    // ── §13 · ตำแหน่งต้องเทียบด้วย id ไม่ใช่ข้อความ ──────────────────────────

    public function test_assignments_can_point_at_a_position_record_while_keeping_the_old_text_column(): void
    {
        $this->assertTrue(Schema::hasColumn('personnel_assignments', 'position'), 'คอลัมน์ข้อความเดิมต้องคงอยู่เพื่อความเข้ากันได้ย้อนหลัง');
        $this->assertTrue(Schema::hasColumn('personnel_assignments', 'position_id'));

        $position = Position::create(['code' => 'head-supply', 'name' => 'หัวหน้างานพัสดุ']);
        $user = User::factory()->create();

        $assignment = PersonnelAssignment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'position' => 'หัวหน้างานพัสดุ',
            'position_id' => $position->id,
            'effective_from' => now()->toDateString(),
        ]);

        $this->assertTrue($assignment->positionRef->is($position));
        $this->assertSame('หัวหน้างานพัสดุ', $assignment->position);
    }

    /**
     * รัน migration ตำแหน่งใหม่ทั้งรอบบนข้อมูลที่มีอยู่จริง
     *
     * ใช้วิธีเดียวกับ `LegacyModernizationMigrationRollbackTest` คือถอย migration
     * แล้วเรียกใหม่ เพื่อให้ **โค้ด backfill ตัวจริง** ได้ทำงานกับข้อมูลที่เตรียมไว้
     * ไม่ใช่ทดสอบตรรกะที่เขียนขึ้นใหม่ในเทสต์ซึ่งพิสูจน์อะไรไม่ได้เลย
     *
     * เคสที่จงใจใส่: ชื่อซ้ำ, ชื่อที่ต่างกันแค่ช่องว่างหัวท้าย, ชื่อว่าง และค่า null
     */
    public function test_the_migration_backfills_positions_from_the_names_already_in_the_data(): void
    {
        $migration = require database_path('migrations/2026_09_03_100000_create_positions_and_link_assignments.php');

        $migration->down();

        $this->assertFalse(Schema::hasTable('positions'));
        $this->assertFalse(Schema::hasColumn('personnel_assignments', 'position_id'));

        $names = ['หัวหน้างานพัสดุ', '  หัวหน้างานพัสดุ  ', 'ครูชำนาญการ', '', null];
        $userIds = [];

        foreach ($names as $name) {
            $user = User::factory()->create();
            $userIds[] = $user->id;

            DB::table('personnel_assignments')->insert([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'position' => $name,
                'effective_from' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $migration->up();

        // ชื่อที่ต่างกันแค่ช่องว่างต้องยุบเป็นตำแหน่งเดียว ส่วนชื่อว่าง/null ไม่สร้างแถวขยะ
        $this->assertSame(
            ['ครูชำนาญการ', 'หัวหน้างานพัสดุ'],
            DB::table('positions')->orderBy('name')->pluck('name')->all()
        );

        $supplyHeadId = DB::table('positions')->where('name', 'หัวหน้างานพัสดุ')->value('id');

        // ทั้งสองแถวที่ชื่อเหมือนกันหลังตัดช่องว่าง ต้องชี้ไปที่ตำแหน่งเดียวกัน
        $this->assertSame(
            [$supplyHeadId, $supplyHeadId],
            DB::table('personnel_assignments')
                ->whereIn('user_id', [$userIds[0], $userIds[1]])
                ->orderBy('user_id')
                ->pluck('position_id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );

        // แถวที่ไม่มีชื่อตำแหน่งต้องถูกปล่อยเป็น null ไม่ใช่ผูกมั่ว
        $this->assertNull(
            DB::table('personnel_assignments')->where('user_id', $userIds[3])->value('position_id')
        );
        $this->assertNull(
            DB::table('personnel_assignments')->where('user_id', $userIds[4])->value('position_id')
        );

        // คอลัมน์ข้อความเดิมต้องไม่ถูกแตะเลย
        $this->assertSame(
            'หัวหน้างานพัสดุ',
            DB::table('personnel_assignments')->where('user_id', $userIds[0])->value('position')
        );
    }

    public function test_the_saraban_foundation_migration_rolls_back_and_reapplies_cleanly(): void
    {
        $migration = require database_path('migrations/2026_09_03_110000_create_saraban_document_foundation.php');

        /*
         * ย้อน migration ของสารบรรณที่มาทีหลังก่อน ตามลำดับที่ `migrate:rollback`
         * ทำจริง (ใหม่สุดก่อนเสมอ)
         *
         * เดิมเทสต์นี้ย้อนตัวเดียวโดด ๆ ซึ่งใช้ได้ตราบใดที่ยังไม่มีตารางอื่นชี้มา
         * พอ INT-13 เพิ่มตารางการเวียนหนังสือที่อ้าง `saraban_document_versions`
         * การถอนผิดลำดับก็ทำให้ SQLite ปฏิเสธทันที · ไล่หาไฟล์แทนการเขียนชื่อ
         * ตายตัว เพื่อให้เช็คพอยต์ถัดไปไม่ต้องกลับมาแก้เทสต์นี้อีก
         */
        $dependents = $this->sarabanMigrationsAfterFoundation();

        foreach (array_reverse($dependents) as $dependent) {
            $dependent->down();
        }

        $migration->down();

        foreach ($this->sarabanTables() as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} ต้องถูกถอนออกตอน rollback");
        }

        $migration->up();

        foreach ($dependents as $dependent) {
            $dependent->up();
        }

        foreach ($this->sarabanTables() as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} ต้องกลับมาตอนรันใหม่");
        }

        $this->assertTrue(Schema::hasColumn('saraban_documents', 'current_version_id'));
    }

    /**
     * migration ของสารบรรณที่รันหลังตัวสร้างฐานเอกสาร เรียงตามลำดับที่รันจริง
     *
     * ไม่รวมตัวที่ถอดโมดูลรุ่นเก่า และไม่รวมตัวที่ไม่ได้สร้างตาราง (เช่นตัวที่เรียก
     * seeder เพิ่มชื่อสิทธิ์) — ตัวหลังไม่มี schema ให้ถอนและ `down()` เป็น no-op
     * อยู่แล้ว จึงเรียกได้โดยไม่มีผลข้างเคียง
     *
     * @return array<int,object>
     */
    private function sarabanMigrationsAfterFoundation(): array
    {
        $files = glob(database_path('migrations/*saraban*.php')) ?: [];
        sort($files);

        $migrations = [];

        foreach ($files as $file) {
            $name = basename($file);

            if ($name <= '2026_09_03_110000_create_saraban_document_foundation.php') {
                continue;
            }

            if (str_contains($name, 'drop_saraban_module')) {
                continue;
            }

            $migrations[] = require $file;
        }

        return $migrations;
    }

    /** @return array<int,string> */
    private function sarabanTables(): array
    {
        return ['saraban_document_types', 'saraban_documents', 'saraban_document_versions'];
    }
}
