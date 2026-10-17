<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\OperationalPermissionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * RBAC-CLEANUP-01 — สิทธิ์ที่ไม่ใช้ถูกลบ และ alias ไม่ให้สิทธิ์เกินตั้งใจ
 */
class RbacCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(OperationalPermissionSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_viewing_the_ot_summary_no_longer_grants_ot_approval(): void
    {
        foreach (['HR Head', 'HR Officer', 'Auditor'] as $role) {
            $user = $this->userWithRole($role);
            $this->assertTrue($user->can('attendance.ot_summary'), "{$role} ยังดูสรุป OT ได้");
            $this->assertFalse($user->can('overtime.management.review'), "{$role} ต้องอนุญาต/รับรอง OT ไม่ได้");
        }

        foreach (['Director', 'Deputy Director'] as $role) {
            $this->assertTrue($this->userWithRole($role)->can('overtime.management.review'), "{$role} ยังอนุมัติ OT ได้");
        }
    }

    public function test_leave_reports_go_to_executives_and_hr_but_not_the_document_officer(): void
    {
        foreach (['Director', 'Deputy Director', 'HR Head'] as $role) {
            $this->assertTrue($this->userWithRole($role)->can('leave.report.view'), "{$role} ต้องดูรายงานการลาได้");
        }

        $this->assertFalse($this->userWithRole('Document Officer')->can('leave.report.view'));
        $this->assertFalse($this->userWithRole('Employee')->can('leave.report.view'));
    }

    public function test_merged_legacy_names_keep_the_same_access(): void
    {
        foreach (['Director', 'Deputy Director', 'HR Head'] as $role) {
            $user = $this->userWithRole($role);
            $this->assertTrue($user->can('leave.approval.dept'), "{$role} ยังเห็นใบลาของหน่วยงานได้");
            $this->assertTrue($user->can('attendance.view_all'), "{$role} ยังเห็นการลงเวลาทั้งหมดได้");
        }
        $this->assertTrue($this->userWithRole('HR Head')->can('overtime.operation.execute'));
    }

    public function test_unused_and_legacy_permissions_are_gone(): void
    {
        $gone = [
            'export_reports', 'delete_users', 'approve_leave', 'view_all_attendances', 'attendance.ot',
            'document.audit.view', 'document.final.view', 'document.generate', 'leave.audit.view', 'leave.evidence.verify',
            'leave.view', 'leave.create', 'leave.cancel', 'leave.approve', 'leave.hr_verify', 'leave.export',
            'attendance.export', 'saraban.view', 'saraban.config.manage', 'saraban.document.archive', 'saraban.document.dispatch',
        ];

        $this->assertSame([], Permission::whereIn('name', $gone)->pluck('name')->all());
        $this->assertSame([], DB::table('permission_aliases')
            ->whereIn('legacy_name', ['attendance.ot_summary', 'export_reports', 'approve_leave', 'view_all_attendances', 'attendance.ot'])
            ->pluck('legacy_name')->all());
    }

    public function test_role_catalogue_only_lists_permissions_that_exist(): void
    {
        $catalogue = collect(config('modules'))->flatMap(fn ($module) => array_keys($module['permissions'] ?? []));
        $missing = $catalogue->diff(Permission::pluck('name'))->values()->all();

        $this->assertSame([], $missing, 'สิทธิ์ในหน้าบทบาทต้องมีอยู่จริงในระบบ');
    }
}
