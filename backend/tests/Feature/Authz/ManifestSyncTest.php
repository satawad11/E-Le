<?php

namespace Tests\Feature\Authz;

use App\Authz\Manifest\ManifestException;
use App\Authz\Manifest\ModuleRegistry;
use App\Authz\Manifest\ModuleSynchronizer;
use App\Authz\PermissionEngine;
use App\Models\Authz\Module;
use App\Models\Authz\Permission;
use App\Models\Authz\PermissionAuditLog;
use App\Models\Authz\PermissionTemplate;
use App\Models\Authz\PositionTemplate;
use App\Models\Authz\TemplatePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R04-1 — รูปแบบ manifest, ModuleRegistry, `modules:sync` (idempotent · retire ไม่ลบ · ไม่ทับการปรับของผู้ดูแล)
 */
class ManifestSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/r04-manifests-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $manifest */
    private function writeManifest(string $folder, array $manifest): void
    {
        File::ensureDirectoryExists("{$this->dir}/{$folder}");
        File::put("{$this->dir}/{$folder}/manifest.php", '<?php return '.var_export($manifest, true).';');
    }

    private function demo(array $override = []): array
    {
        return array_replace_recursive([
            'code' => 'demo', 'name' => 'ทดสอบ', 'version' => '1.0.0',
            'features' => [
                'item' => ['name' => 'รายการ', 'actions' => [
                    'view' => ['name' => 'ดู', 'scopes' => ['own', 'all']],
                    'approve' => ['name' => 'อนุมัติ', 'scopes' => ['all'], 'requires' => ['item.view'], 'risk' => 'high', 'rules' => ['not_own']],
                ]],
            ],
            'templates' => ['staff' => ['name' => 'บุคลากร', 'kind' => 'positional', 'permissions' => ['demo.item.view' => 'own']]],
            'defaults' => ['L5' => ['staff']],
            'menu_groups' => [['code' => 'demo', 'name' => 'ทดสอบ', 'order' => 50]],
            'menus' => [['code' => 'demo.list', 'group' => 'demo', 'name' => 'รายการ', 'route' => '/demo', 'permission' => 'demo.item.view']],
        ], $override);
    }

    private function synchronizer(): ModuleSynchronizer
    {
        return new ModuleSynchronizer(new ModuleRegistry($this->dir), app(PermissionEngine::class));
    }

    public function test_project_manifests_are_valid_and_sync_through_the_command(): void
    {
        $this->artisan('modules:sync')->assertSuccessful();

        $registry = app(ModuleRegistry::class);
        $this->assertSame(['access', 'audit', 'core', 'document', 'notification', 'org', 'personnel', 'system', 'workflow'], array_keys($registry->all()));
        $this->assertSame(count($registry->permissions()), Permission::whereNull('retired_at')->count());
        $this->assertSame(count($registry->templates()), PermissionTemplate::count());
        $this->assertSame(['personnel.profile.view'], Permission::where('name', 'personnel.profile.view_confidential')->value('requires'));
    }

    public function test_sync_is_idempotent(): void
    {
        $this->writeManifest('Demo', $this->demo());

        $first = $this->synchronizer()->sync();
        $counts = [Permission::count(), TemplatePermission::count(), PositionTemplate::count(), Module::count()];
        $second = $this->synchronizer()->sync();

        $this->assertSame(2, $first['permissions_created']);
        $this->assertSame(0, $second['permissions_created']);
        $this->assertSame(0, $second['template_permissions_added']);
        $this->assertSame(0, $second['position_templates_added']);
        $this->assertSame($counts, [Permission::count(), TemplatePermission::count(), PositionTemplate::count(), Module::count()]);

        $permission = Permission::where('name', 'demo.item.approve')->firstOrFail();
        $this->assertSame(['demo.item.view'], $permission->requires);
        $this->assertSame('high', $permission->risk);
        $this->assertSame(['not_own'], $permission->rules);
        $this->assertTrue(PositionTemplate::whereNull('position_id')->where('org_unit_type_level', 5)->exists());
        $this->assertTrue(PermissionAuditLog::where('action', 'permission.created')->exists());
    }

    public function test_sync_keeps_admin_changes_unless_reset_defaults(): void
    {
        $this->writeManifest('Demo', $this->demo());
        $this->synchronizer()->sync();

        $row = TemplatePermission::firstOrFail();
        $row->update(['scope' => 'all']); // ผู้ดูแลขยายขอบเขต

        $this->synchronizer()->sync();
        $this->assertSame('all', $row->fresh()->scope, 'sync ปกติไม่ทับการปรับของผู้ดูแล');

        $this->synchronizer()->sync(resetDefaults: true);
        $this->assertSame('own', TemplatePermission::firstOrFail()->scope, '--reset-defaults คืนค่าตาม manifest');
    }

    public function test_denies_follow_the_manifest_exactly_and_patterns_skip_the_templates_own_permissions(): void
    {
        $manifest = $this->demo(['templates' => ['admin' => ['name' => 'ผู้ดูแล', 'kind' => 'functional',
            'permissions' => ['demo.item.approve' => 'all'], 'denies' => ['*.*.approve' => null, 'demo.item.view' => 'own']]]]);
        $this->writeManifest('Demo', $manifest);
        $this->synchronizer()->sync();

        $template = PermissionTemplate::where('code', 'demo.admin')->firstOrFail();
        $rows = fn () => TemplatePermission::where('template_id', $template->id)->with('permission')->get()
            ->mapWithKeys(fn ($r) => [$r->permission->name => $r->effect.':'.($r->scope ?? 'null')])->sort()->all();

        $this->assertSame(['demo.item.approve' => 'allow:all', 'demo.item.view' => 'deny:own'], $rows(), 'pattern ไม่ปฏิเสธสิทธิ์ที่ชุดสิทธิ์ให้เอง');

        unset($manifest['templates']['admin']['denies']);
        $this->writeManifest('Demo', $manifest);
        $this->synchronizer()->sync();

        $this->assertSame(['demo.item.approve' => 'allow:all'], $rows(), 'deny ที่ manifest เลิกประกาศต้องหายไปโดยไม่ต้อง --reset-defaults');
    }

    public function test_permission_removed_from_manifest_is_retired_not_deleted(): void
    {
        $this->writeManifest('Demo', $this->demo());
        $this->synchronizer()->sync();

        $manifest = $this->demo();
        unset($manifest['features']['item']['actions']['approve']);
        $this->writeManifest('Demo', $manifest);
        $stats = $this->synchronizer()->sync();

        $retired = Permission::where('name', 'demo.item.approve')->firstOrFail();
        $this->assertNotNull($retired->retired_at);
        $this->assertSame(1, $stats['permissions_retired']);

        $this->writeManifest('Demo', $this->demo());
        $this->synchronizer()->sync();
        $this->assertNull($retired->fresh()->retired_at, 'กลับมาใน manifest = ใช้งานอีกครั้ง');
    }

    public function test_module_enabled_flag_is_kept_by_sync(): void
    {
        $this->writeManifest('Demo', $this->demo());
        $this->synchronizer()->sync();
        Module::where('code', 'demo')->update(['enabled' => false]);

        $this->synchronizer()->sync();

        $this->assertFalse(Module::where('code', 'demo')->value('enabled'));
        $this->assertFalse((new ModuleRegistry($this->dir))->isEnabled('demo'));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidManifests(): array
    {
        return [
            'requires ไม่มีอยู่' => [['features' => ['item' => ['actions' => ['approve' => ['requires' => ['item.missing']]]]]], 'requires'],
            'scope ไม่รู้จัก' => [['features' => ['item' => ['actions' => ['view' => ['scopes' => ['galaxy']]]]]], 'scope'],
            'กฎไม่รู้จัก' => [['features' => ['item' => ['actions' => ['view' => ['rules' => ['bypass_everything']]]]]], 'กฎ'],
            'template ใช้ scope ที่ไม่อนุญาต' => [['templates' => ['staff' => ['permissions' => ['demo.item.approve' => 'own']]]], 'ไม่รองรับ scope'],
            'เมนูไม่มีกลุ่ม' => [['menus' => [['code' => 'demo.list', 'group' => 'ghost']]], 'ไม่มีกลุ่ม'],
            'เมนูอ้าง permission ที่ไม่มี' => [['menus' => [['code' => 'demo.list', 'permission' => 'demo.item.fly']]], 'ไม่มี permission'],
            'defaults อ้าง functional' => [['templates' => ['staff' => ['kind' => 'functional']]], 'positional'],
        ];
    }

    /** @param  array<string, mixed>  $override */
    #[DataProvider('invalidManifests')]
    public function test_invalid_manifest_is_rejected(array $override, string $expected): void
    {
        $this->writeManifest('Demo', $this->demo($override));

        try {
            (new ModuleRegistry($this->dir))->all();
            $this->fail('manifest ไม่ถูกต้องต้องถูกปฏิเสธ');
        } catch (ManifestException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }

        $this->assertSame(0, Permission::count());
    }
}
