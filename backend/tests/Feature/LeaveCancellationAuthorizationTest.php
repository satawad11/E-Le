<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * LEGACY-04 · 04-4 (4/4) — ล็อกชุดผู้มีสิทธิ์ยกเลิกใบลาของผู้อื่น
 *
 * เดิมชุดนี้คือ {Super Admin, HR Head} โดย HR Head ผ่านด้วย **ชื่อ role เท่านั้น**
 * เพราะยังไม่มีใครถือ `leave.management.manage` จริงในฐานข้อมูล
 *
 * ถ้า refactor แล้วใช้ `leave.management.manage` อย่างเดียว HR Head จะเสียสิทธิ์
 * ที่เคยมี เทสต์ชุดนี้กันไม่ให้เกิดกรณีนั้น และกันไม่ให้บทบาทอื่นได้สิทธิ์เพิ่ม
 *
 * SEC-027 (2026-09-01) — แก้ทางที่ HR Head ผ่านด่านนี้
 *
 * รอบนี้ตัด `system.user.view` ออกจากชุดที่ให้อำนาจยกเลิกใบลาของผู้อื่น เพราะเป็น
 * สิทธิ์ "ดูทะเบียนผู้ใช้" ไม่ใช่สิทธิ์งานลา ใครก็ตามที่ได้สิทธิ์ไว้เพียงเพื่อเปิดดู
 * รายชื่อบุคลากรจึงยกเลิกใบลาของคนทั้งองค์กรได้
 *
 * HR Head ยังทำได้เหมือนเดิม แต่ผ่าน `leave.approval.hr` ซึ่งเป็นสิทธิ์งานลาโดยตรง
 * และ `OperationalPermissionSeeder` มอบให้บทบาทนี้อยู่แล้ว (ดู TASKS.md รอบสี่)
 */
class LeaveCancellationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Leave $othersLeave;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'leave.management.manage', 'system.user.view', 'leave.approval.hr',
            'leave.report.view', 'leave.approval.dept', 'attendance.view_all',
        ] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $department = OrgUnit::factory()->create(['name' => 'ฝ่ายทดสอบ']);
        $type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);
        $owner = User::factory()->create(['org_unit_id' => $department->id]);

        $this->othersLeave = Leave::create([
            'user_id' => $owner->id,
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'total_days' => 2,
            'reason' => 'ของคนอื่น',
            'status' => 'Pending',
        ]);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function cancel(User $user): int
    {
        return $this->actingAs($user)
            ->postJson("/api/leaves/{$this->othersLeave->id}/cancel")
            ->status();
    }

    /** HR Head ถือ leave.approval.hr — ต้องยังยกเลิกใบลาของผู้อื่นได้เหมือนเดิม */
    public function test_hr_head_can_still_cancel_someone_elses_leave(): void
    {
        $this->assertNotSame(403, $this->cancel($this->userWith(['leave.approval.hr'])));
    }

    /**
     * SEC-027 — สิทธิ์ "ดูทะเบียนผู้ใช้" ต้องไม่แถมอำนาจยกเลิกใบลาของผู้อื่นมาด้วย
     */
    public function test_user_directory_permission_alone_cannot_cancel_someone_elses_leave(): void
    {
        $this->assertSame(403, $this->cancel($this->userWith(['system.user.view'])));
    }

    public function test_leave_manager_can_cancel_someone_elses_leave(): void
    {
        $this->assertNotSame(403, $this->cancel($this->userWith(['leave.management.manage'])));
    }

    /**
     * Director / Deputy ถือ leave.report.view + leave.approval.dept + attendance.view_all
     * แต่ไม่ได้ถือ system.user.view จึงต้องยกเลิกใบลาของผู้อื่นไม่ได้ เหมือนเดิม
     */
    public function test_director_level_permissions_do_not_grant_cancelling_others_leave(): void
    {
        $viewer = $this->userWith(['leave.report.view', 'leave.approval.dept', 'attendance.view_all']);

        $this->assertSame(403, $this->cancel($viewer));
    }

    public function test_ordinary_user_cannot_cancel_someone_elses_leave(): void
    {
        $this->assertSame(403, $this->cancel($this->userWith([])));
    }
}
