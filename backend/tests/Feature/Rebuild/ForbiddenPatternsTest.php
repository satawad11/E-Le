<?php

namespace Tests\Feature\Rebuild;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * R02-12 — grep ไม่พบ spatie/laravel-permission, hasRole( และ allow-all ก่อน Gate (AGENTS.md hard gates)
 *
 * ตรวจโค้ดที่ Laravel โหลดจริง · `database/migrations-legacy/` ไม่ถูกโหลดจึงไม่นับ
 */
class ForbiddenPatternsTest extends TestCase
{
    private const SCANNED = ['app', 'bootstrap', 'config', 'routes', 'database/migrations', 'database/seeders', 'database/factories'];

    /** @return array<string, string> path => contents */
    private function sources(): array
    {
        $files = [];
        foreach (self::SCANNED as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[$dir.'/'.$file->getRelativePathname()] = $file->getContents();
                }
            }
        }

        return $files;
    }

    public function test_no_forbidden_authorization_patterns_in_loaded_code(): void
    {
        $patterns = [
            'Spatie'.chr(92).'Permission' => '/Spatie\\\\Permission/',
            'hasRole(' => '/hasRole\(/',
            'Gate::before' => '/Gate::before/',
            'HasRoles trait' => '/\bHasRoles\b/',
            'spatie permission scope' => '/User::permission\(/',
        ];

        $hits = [];
        foreach ($this->sources() as $path => $contents) {
            foreach ($patterns as $label => $regex) {
                if (preg_match($regex, $contents)) {
                    $hits[] = "{$label} ใน {$path}";
                }
            }
        }

        $this->assertSame([], $hits);
    }

    public function test_spatie_permission_package_is_removed(): void
    {
        $composer = json_decode(File::get(base_path('composer.json')), true);

        $this->assertArrayNotHasKey('spatie/laravel-permission', $composer['require']);
        $this->assertStringNotContainsString('"name": "spatie/laravel-permission"', File::get(base_path('composer.lock')));
        $this->assertFileDoesNotExist(config_path('permission.php'));
        $this->assertFalse(class_exists('Spatie'.chr(92).'Permission'.chr(92).'PermissionServiceProvider'));
    }

    public function test_legacy_seeders_listed_in_plan_are_deleted(): void
    {
        foreach (['RolesAndPermissionsSeeder', 'ModulePermissionSeeder', 'OperationalPermissionSeeder', 'SarabanPermissionSeeder',
            'MockDataSeeder', 'MockLeaveSeeder', 'UpdateUserNamesSeeder', 'OrgUnitBackfillSeeder', 'MenuSeeder', 'LeaveWorkflowSeeder'] as $seeder) {
            $this->assertFileDoesNotExist(database_path("seeders/{$seeder}.php"), "PLAN §4.3 สั่งลบ {$seeder}");
        }
    }

    public function test_models_of_removed_tables_are_deleted(): void
    {
        foreach (['ApprovalStep', 'Department', 'Menu', 'OrgUnitManager', 'OrgUnitMember', 'PersonnelAssignment', 'Role', 'UserProfile', 'UserRoleGrant'] as $model) {
            $this->assertFileDoesNotExist(app_path("Models/{$model}.php"));
        }
    }
}
