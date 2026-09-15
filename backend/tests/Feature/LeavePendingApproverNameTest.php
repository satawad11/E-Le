<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ชื่อในช่องลงนามที่ยังไม่มีใครเซ็น ต้องมาจากผู้มีอำนาจจริงของระบบ
 *
 * **ที่มา:** ชื่อนี้เคยมาจากช่องข้อความที่ผู้ดูแลพิมพ์เองในหน้าตั้งค่า
 * (`head_hr_name`, `deputy_admin_name`, `director_name`) ซึ่งเป็นข้อมูลคนละชุดกับ
 * ทะเบียนผู้มีอำนาจจริง · เมื่อเปลี่ยนตัวผู้รับผิดชอบแต่ไม่มีใครกลับไปแก้ช่องข้อความ
 * ใบลาเปล่าที่พิมพ์ไปให้เสนอเซ็นจะขึ้นชื่อคนที่ไม่ใช่ผู้มีอำนาจลงนามแล้ว
 *
 * เทสต์นี้ตรึงลำดับความสำคัญสามชั้น: ผู้มีอำนาจจริง > ค่าที่ตั้งไว้ > เส้นประ
 * และตรึงว่า **ห้ามเดา** เมื่อผู้มีอำนาจมีหลายคน
 */
class LeavePendingApproverNameTest extends TestCase
{
    use RefreshDatabase;

    private function pendingLeave(): Leave
    {
        $unit = OrgUnit::factory()->create(['name' => 'แผนกวิชาทดสอบ']);
        $requester = User::factory()->create(['org_unit_id' => $unit->id, 'is_active' => true]);
        $type = LeaveType::create(['name' => 'ลาป่วย', 'code_prefix' => 'S']);

        return Leave::create([
            'user_id' => $requester->id,
            'leave_type_id' => $type->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'total_days' => 1,
            'reason' => 'ทดสอบชื่อผู้ลงนาม',
            'status' => 'Pending',
            'approval_level' => 0,
        ])->fresh();
    }

    /** สายอนุมัติขั้นเดียว: ผู้อำนวยการ */
    private function directorStep(): Role
    {
        $director = Role::firstOrCreate(['name' => 'Director', 'guard_name' => 'web']);

        ApprovalStep::create([
            'step_order' => 1,
            'role_id' => $director->id,
            'name' => 'อนุมัติโดยผู้อำนวยการ',
        ]);

        return $director;
    }

    private function exportBody(Leave $leave): string
    {
        return $this->actingAs($leave->user)
            ->get("/api/leaves/{$leave->id}/word")
            ->assertOk()
            ->getContent();
    }

    public function test_it_prints_the_real_current_approver_instead_of_the_name_typed_in_settings(): void
    {
        $leave = $this->pendingLeave();
        $director = $this->directorStep();

        // ชื่อที่ผู้ดูแลพิมพ์ไว้เมื่อนานมาแล้ว — ตอนนี้ไม่ใช่ผู้อำนวยการแล้ว
        Setting::create(['key' => 'director_name', 'value' => 'นายอดีต ผู้อำนวยการเก่า', 'type' => 'string']);

        $current = User::factory()->create(['name' => 'นายปัจจุบัน ผู้อำนวยการจริง', 'is_active' => true]);
        $current->assignRole($director);

        $body = $this->exportBody($leave);

        $this->assertStringContainsString('นายปัจจุบัน ผู้อำนวยการจริง', $body);
        $this->assertStringNotContainsString('นายอดีต ผู้อำนวยการเก่า', $body);
    }

    public function test_it_falls_back_to_the_configured_name_when_nobody_holds_the_role(): void
    {
        $leave = $this->pendingLeave();
        $this->directorStep();

        Setting::create(['key' => 'director_name', 'value' => 'นายสำรอง ตามค่าที่ตั้งไว้', 'type' => 'string']);

        $this->assertStringContainsString('นายสำรอง ตามค่าที่ตั้งไว้', $this->exportBody($leave));
    }

    public function test_it_never_guesses_when_more_than_one_person_holds_the_role(): void
    {
        $leave = $this->pendingLeave();
        $director = $this->directorStep();

        Setting::create(['key' => 'director_name', 'value' => 'นายสำรอง ตามค่าที่ตั้งไว้', 'type' => 'string']);

        foreach (['นายหนึ่ง รักษาการ', 'นายสอง รักษาการ'] as $name) {
            User::factory()->create(['name' => $name, 'is_active' => true])->assignRole($director);
        }

        $body = $this->exportBody($leave);

        // เดาไปคนหนึ่งผิดยิ่งกว่าเว้นว่าง — ตกไปใช้ค่าที่ตั้งไว้แทน
        $this->assertStringContainsString('นายสำรอง ตามค่าที่ตั้งไว้', $body);
        $this->assertStringNotContainsString('นายหนึ่ง รักษาการ', $body);
        $this->assertStringNotContainsString('นายสอง รักษาการ', $body);
    }

    public function test_it_leaves_a_blank_line_when_there_is_neither_a_holder_nor_a_configured_name(): void
    {
        $leave = $this->pendingLeave();
        $this->directorStep();

        $this->assertStringContainsString('........................................', $this->exportBody($leave));
    }
}
