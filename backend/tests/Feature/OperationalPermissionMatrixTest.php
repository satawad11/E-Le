<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\OperationalPermissionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * LEGACY-04 (ปิดงาน) — ล็อกการแจกสิทธิ์ตามที่เจ้าของระบบตัดสินเมื่อ 2026-08-14
 *
 * เทสต์ชุดนี้ไม่ได้ตรวจแค่ว่า "ให้แล้ว" แต่ตรวจว่า **ไม่ได้ให้เกิน** ด้วย
 * เพราะข้อกำหนดด้านความปลอดภัยของงานนี้คือการเติมสิทธิ์ต้องไม่ทำให้ผู้ใช้
 * เห็นข้อมูลเพิ่มเกินหน้าที่ (Principle of Least Privilege)
 *
 * ที่ผ่านมาความผิดพลาดของเฟสนี้ทุกครั้งมาจากการเดาว่าใครถืออะไร
 * เทสต์จึงระบุชื่อ role กับชื่อ permission ตรง ๆ ให้อ่านแล้วเห็นเจตนาทันที
 */
class OperationalPermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_all_seven_approved_permissions_now_exist(): void
    {
        $approved = [
            'attendance.checkin',
            'attendance.view_self',
            'attendance.ot',
            'attendance.ot_summary',
            'system.menu.manage',
            'admin.users.manage_permissions',
        ];

        $catalogue = Permission::pluck('name')->all();

        foreach ($approved as $name) {
            $this->assertContains($name, $catalogue, "permission `{$name}` ที่ได้รับอนุมัติให้สร้างยังไม่มีในฐานข้อมูล");
        }
    }

    /**
     * ข้อบกพร่องที่กระทบการใช้งานจริงมากที่สุดของ LEGACY-04
     *
     * route `POST /api/attendances/check-in` บังคับ
     * `permission:attendance.checkin|attendance.view_self`
     * ซึ่งทั้งสองชื่อไม่เคยมีอยู่ ทำให้ทุก role ยกเว้น Super Admin ลงเวลาไม่ได้
     */
    public function test_every_role_that_reports_for_duty_can_clock_in(): void
    {
        $roles = ['Employee', 'Document Officer', 'HR Head', 'Deputy Director', 'Director', 'Super Admin'];

        foreach ($roles as $role) {
            $user = $this->userWithRole($role);

            $this->assertTrue(
                $user->can('attendance.checkin'),
                "role `{$role}` ต้องลงเวลาเข้า-ออกได้",
            );
            $this->assertTrue(
                $user->can('attendance.view_self'),
                "role `{$role}` ต้องดูประวัติการลงเวลาของตนเองได้",
            );
        }
    }

    /**
     * การเติมสิทธิ์ลงเวลาต้องไม่พาสิทธิ์ระดับองค์กรติดมาด้วย
     */
    public function test_ordinary_staff_gain_nothing_beyond_their_own_attendance(): void
    {
        $employee = $this->userWithRole('Employee');

        $mustNotHave = [
            'attendance.view_all',
            'view_all_attendances',
            'attendance.export',
            'attendance.ot',
            'attendance.ot_summary',
            'system.menu.manage',
            'admin.users.manage_permissions',
        ];

        foreach ($mustNotHave as $ability) {
            $this->assertFalse(
                $employee->can($ability),
                "บุคลากรทั่วไปต้องไม่ได้สิทธิ์ `{$ability}`",
            );
        }
    }

    /**
     * OT แจกตามหน้าที่จริง ไม่แจกทั้งกระดาน (Decision 1)
     */
    public function test_overtime_permissions_follow_actual_duty(): void
    {
        $this->assertTrue($this->userWithRole('HR Head')->can('attendance.ot'), 'HR เป็นผู้ดูแล OT จึงต้องบันทึก OT ได้');

        foreach (['Employee', 'Document Officer', 'Director', 'Deputy Director'] as $role) {
            $this->assertFalse(
                $this->userWithRole($role)->can('attendance.ot'),
                "role `{$role}` ไม่ใช่ผู้ดูแล OT จึงต้องไม่ได้ `attendance.ot`",
            );
        }

        // สรุป OT ทั้งองค์กร ให้เฉพาะผู้ที่เห็นการลงเวลาทั้งองค์กรอยู่แล้ว
        foreach (['HR Head', 'Director', 'Deputy Director'] as $role) {
            $user = $this->userWithRole($role);
            $this->assertTrue($user->can('attendance.ot_summary'), "role `{$role}` ต้องดูสรุป OT ได้");
            $this->assertTrue(
                $user->can('attendance.view_all'),
                "role `{$role}` ต้องถือ attendance.view_all อยู่ก่อนแล้ว มิฉะนั้นการให้ ot_summary คือการเปิดข้อมูลชั้นใหม่",
            );
        }

        foreach (['Employee', 'Document Officer'] as $role) {
            $this->assertFalse(
                $this->userWithRole($role)->can('attendance.ot_summary'),
                "role `{$role}` ต้องไม่เห็นสรุป OT ของทั้งองค์กร",
            );
        }
    }

    /**
     * สิทธิ์ที่กระทบความปลอดภัยของทั้งระบบต้องไม่ตกไปอยู่กับ role ใดนอกจาก Super Admin
     *
     * โดยเฉพาะ `admin.users.manage_permissions` — ถ้า role ที่ไม่ใช่ผู้ดูแลระบบถือไว้
     * จะยกระดับสิทธิ์ตนเองได้ทันที
     */
    public function test_system_wide_powers_belong_to_super_admin_only(): void
    {
        $restricted = [
            'system.menu.manage',
            'admin.users.manage_permissions',
        ];

        foreach ($restricted as $ability) {
            $holders = Role::whereHas('permissions', fn ($query) => $query->where('name', $ability))
                ->pluck('name')
                ->reject(fn (string $name) => in_array($name, OperationalPermissionSeeder::SUPER_ADMIN_ROLES, true))
                ->values()
                ->all();

            $this->assertSame(
                [],
                $holders,
                "`{$ability}` ต้องเป็นของ Super Admin เท่านั้น แต่ role เหล่านี้ถืออยู่: ".implode(', ', $holders),
            );
        }
    }

    /**
     * มอบหมายรายบุคคลได้จริง — เป็นทางที่ Decision 1 กำหนดไว้สำหรับสิทธิ์
     * ที่ไม่ผูกกับ role ใดเลย ("เฉพาะผู้ได้รับมอบหมาย")
     */
    public function test_restricted_permissions_can_still_be_granted_to_one_person(): void
    {
        $officer = $this->userWithRole('Document Officer');
        $this->assertFalse($officer->can('system.menu.manage'));

        $officer->givePermissionTo('system.menu.manage');

        $this->assertTrue($officer->fresh()->can('system.menu.manage'));

        // และต้องไม่หลุดไปทั้ง role
        $this->assertFalse(
            $this->userWithRole('Document Officer')->can('system.menu.manage'),
            'การมอบหมายรายบุคคลต้องไม่กลายเป็นสิทธิ์ของทั้ง role',
        );
    }

    /*
     * หัวหน้าบุคลากรต้องตรวจสอบสถิติการลาได้
     *
     * ปุ่ม "ตรวจสอบสถิติการลา" บังคับสิทธิ์ `leave.approval.hr` แต่เดิมไม่มีบทบาทใด
     * ได้รับเลยนอกจาก Super Admin — รวมถึง `HR Head` ซึ่งเป็นผู้ทำหน้าที่นี้จริง
     * และเป็น role ที่ผูกกับขั้นตอน "ตรวจสอบโดยหัวหน้าบุคลากร" ในสายอนุมัติมาตรฐาน
     *
     * ที่ผ่านมาไม่มีใครสังเกตเพราะบัญชีที่ใช้ทดสอบถูกมอบบทบาท Super Admin ไว้
     * ซึ่งผ่าน `Gate::before` ทุกกรณีโดยไม่เกี่ยวกับสิทธิ์ที่ถืออยู่จริง
     */
    public function test_hr_head_can_verify_leave_statistics(): void
    {
        $this->seed(OperationalPermissionSeeder::class);

        $this->assertTrue(
            $this->userWithRole('HR Head')->can('leave.approval.hr'),
            'หัวหน้าบุคลากรต้องกดปุ่มตรวจสอบสถิติการลาได้',
        );
    }

    /** สิทธิ์นี้เป็นของงานบุคลากร ไม่ใช่ของทุกคนที่อยู่ในสายอนุมัติ */
    public function test_leave_statistics_verification_is_not_handed_to_every_approver(): void
    {
        $this->seed(OperationalPermissionSeeder::class);

        foreach (['Employee', 'Deputy Director', 'Director', 'Document Officer'] as $role) {
            $this->assertFalse(
                $this->userWithRole($role)->can('leave.approval.hr'),
                "บทบาท {$role} ไม่ควรได้สิทธิ์ตรวจสอบสถิติการลา",
            );
        }
    }

    /**
     * seeder ต้องรันซ้ำได้โดยไม่แจกสิทธิ์เพิ่มและไม่ถอนของเดิม
     */
    public function test_seeding_twice_changes_nothing(): void
    {
        $before = $this->grantSnapshot();

        $this->seed(OperationalPermissionSeeder::class);
        $this->seed(OperationalPermissionSeeder::class);

        $this->assertSame($before, $this->grantSnapshot(), 'การรัน seeder ซ้ำต้องไม่เปลี่ยนสิทธิ์ของใคร');
    }

    /** @return array<string,array<int,string>> */
    private function grantSnapshot(): array
    {
        return Role::with('permissions')
            ->get()
            ->mapWithKeys(fn (Role $role) => [
                $role->name => $role->permissions->pluck('name')->sort()->values()->all(),
            ])
            ->sortKeys()
            ->all();
    }
}
