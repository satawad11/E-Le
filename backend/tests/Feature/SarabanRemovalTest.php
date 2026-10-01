<?php

namespace Tests\Feature;

use App\Models\Menu;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ถอดโมดูลสารบรรณ V1.2 (2026-10-01) — migration ต้องไม่ทำลายข้อมูลที่ยังไม่ได้สำรอง
 * และต้องเก็บร่องรอยในตารางกลางให้หมดโดยไม่แตะบทบาทหรือโมดูลอื่น
 */
class SarabanRemovalTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_01_110000_drop_saraban_v12_module';

    protected function tearDown(): void
    {
        putenv('SARABAN_DROP_CONFIRMED');

        parent::tearDown();
    }

    private function runMigration(): void
    {
        (require database_path('migrations/'.self::MIGRATION.'.php'))->up();
    }

    /** ระบบที่ติดตั้งแล้วยังมีตารางของสารบรรณ — ฐานทดสอบไม่มี จึงจำลองขึ้น */
    private function installedTable(string $name): void
    {
        Schema::create($name, function (Blueprint $table) {
            $table->id();
            $table->string('label')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_refuses_to_drop_tables_that_hold_real_records_without_confirmation(): void
    {
        $this->installedTable('saraban_documents');
        DB::table('saraban_documents')->insert(['label' => 'หนังสือจริง']);
        Permission::findOrCreate('saraban.document.view', 'web');

        try {
            $this->runMigration();
            $this->fail('migration ต้องหยุดเมื่อมีข้อมูลการใช้งานจริงและยังไม่ยืนยัน');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('saraban_documents', $exception->getMessage());
            $this->assertStringContainsString('SARABAN_DROP_CONFIRMED', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('saraban_documents'), 'ต้องไม่มีอะไรถูกลบเมื่อหยุด');
        $this->assertSame(1, DB::table('saraban_documents')->count());
        $this->assertTrue(Permission::where('name', 'saraban.document.view')->exists());
    }

    public function test_it_drops_everything_once_the_backup_is_confirmed(): void
    {
        $this->installedTable('saraban_documents');
        DB::table('saraban_documents')->insert(['label' => 'หนังสือจริง']);
        putenv('SARABAN_DROP_CONFIRMED=true');

        $this->runMigration();

        $this->assertFalse(Schema::hasTable('saraban_documents'));
    }

    public function test_seeded_reference_tables_alone_do_not_block_the_removal(): void
    {
        $this->installedTable('saraban_document_types');
        DB::table('saraban_document_types')->insert(['label' => 'หนังสือภายนอก']);

        $this->runMigration();

        $this->assertFalse(Schema::hasTable('saraban_document_types'));
    }

    public function test_central_traces_are_removed_but_roles_and_other_modules_stay(): void
    {
        $role = Role::findOrCreate('เจ้าหน้าที่สารบรรณกลาง', 'web');
        $role->givePermissionTo(Permission::findOrCreate('saraban.inbox.view', 'web'));
        $role->givePermissionTo(Permission::findOrCreate('leave.create', 'web'));

        $parent = Menu::create(['code' => 'saraban', 'title_th' => 'งานสารบรรณ', 'title_en' => 'Saraban', 'module' => 'saraban', 'sort_order' => 9]);
        Menu::create(['code' => 'saraban.inbox', 'title_th' => 'กล่อง', 'title_en' => 'Inbox', 'module' => 'saraban', 'parent_id' => $parent->id, 'sort_order' => 1]);

        DB::table('feature_flags')->insert([
            'uuid' => (string) Str::uuid(), 'key' => 'saraban.distribution.all_authorized',
            'name' => 'เวียนถึงทุกคน', 'module' => 'saraban', 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigration();

        $this->assertFalse(Permission::where('name', 'like', 'saraban.%')->exists());
        $this->assertSame(0, Menu::where('module', 'saraban')->count());
        $this->assertDatabaseMissing('feature_flags', ['key' => 'saraban.distribution.all_authorized']);

        $role = Role::findByName('เจ้าหน้าที่สารบรรณกลาง', 'web');
        $this->assertTrue($role->hasPermissionTo('leave.create'), 'บทบาทและสิทธิ์ของโมดูลอื่นต้องอยู่ครบ');
    }

    public function test_nothing_of_the_module_is_left_in_the_application(): void
    {
        $this->assertFalse(config('modules.saraban') !== null, 'module registry ต้องไม่มี saraban');
        $this->assertFalse(is_dir(app_path('Services/Saraban')));
        $this->assertFalse(is_dir(app_path('Http/Controllers/Saraban')));
        $this->assertSame([], glob(database_path('migrations/*create_saraban*')) ?: []);
    }
}
