<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Services\LeaveDocumentFields;
use App\Services\LeaveVisibility;
use App\Services\OrgScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ผังองค์กรต้องมีชุดเดียว — `org_units`
 *
 * ระบบมีโครงหน่วยงานสองชุดอยู่พร้อมกัน: `departments` แบบชั้นเดียวของเดิม กับ
 * `org_units` แบบหลายชั้นที่การอนุมัติ ขอบเขตการมองเห็น และการหาหัวหน้าใช้จริง
 * ทั้งสองถูกประคองให้ตรงกันด้วย `department_org_unit_map` แต่เมื่อใดที่ไม่ตรงกัน
 * **เอกสารกับรายงานจะบอกหน่วยงานคนละชุดกับที่ระบบใช้ตัดสินใจ**
 *
 * เทสต์ชุดนี้ตรึงไว้ว่าทุกที่อ่านจากผังจริงชุดเดียว
 */
class OrgUnitSingleSourceTest extends TestCase
{
    use RefreshDatabase;

    private function orgUnit(string $name, ?int $parentId = null): int
    {
        return DB::table('org_units')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'code' => Str::random(8),
            'name' => $name,
            'type' => $parentId === null ? 'DIVISION' : 'GROUP',
            'parent_id' => $parentId,
            'effective_from' => now()->subYear()->toDateString(),
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_แบบฟอร์มแยกฝ่ายกับงานตามลำดับชั้นจริง(): void
    {
        /*
         * แบบฟอร์มราชการมีช่อง "ฝ่าย/กลุ่ม" กับ "แผนก/งาน" แยกกัน
         * เดิมระบบเติมชื่อแผนกเดิมลงทั้งสองช่อง ผู้ลาจึงได้เอกสารที่ซ้ำกันสองบรรทัด
         */
        $division = $this->orgUnit('ฝ่ายบริหารทรัพยากร');
        $group = $this->orgUnit('งานบุคลากร', $division);

        $user = User::factory()->create(['org_unit_id' => $group]);

        $names = app(OrgScopeResolver::class)->homeOrgUnitNames($user);

        $this->assertSame('งานบุคลากร', $names['unit']);
        $this->assertSame('ฝ่ายบริหารทรัพยากร', $names['parent']);
    }

    public function test_หน่วยงานบนสุดใช้ชื่อตัวเองแทนช่องว่าง(): void
    {
        $root = $this->orgUnit('วิทยาลัยเทคนิคทดสอบ');
        $user = User::factory()->create(['org_unit_id' => $root]);

        $names = app(OrgScopeResolver::class)->homeOrgUnitNames($user);

        $this->assertSame('วิทยาลัยเทคนิคทดสอบ', $names['unit']);
        $this->assertSame('วิทยาลัยเทคนิคทดสอบ', $names['parent']);
    }

    public function test_ไม่เอาชื่อองค์กรยอดผังมาใส่ช่องฝ่าย(): void
    {
        /*
         * ผังเริ่มต้นของระบบเป็นแบบชั้นเดียว — ทุกแผนกแขวนใต้ ORG-ROOT ซึ่งแทนตัว
         * สถานศึกษาเอง และตั้งชื่อจาก `app.name` · ถ้าเอามาใส่ช่อง "ฝ่าย" ผู้ลาจะได้
         * เอกสารที่เขียนว่าสังกัดฝ่ายชื่อเดียวกับชื่อระบบ
         */
        $department = Department::create(['name' => 'แผนกวิชาช่างยนต์']);
        $user = User::factory()->create(['department_id' => $department->id]);

        $names = app(OrgScopeResolver::class)->homeOrgUnitNames($user->fresh());

        $this->assertSame('แผนกวิชาช่างยนต์', $names['unit']);
        $this->assertSame('แผนกวิชาช่างยนต์', $names['parent']);
    }

    public function test_ใบลาที่พิมพ์บอกหน่วยงานเดียวกับที่ระบบใช้เดินสายอนุมัติ(): void
    {
        /*
         * กรณีที่ผังสองชุดไม่ตรงกัน — เกิดได้จริงเมื่อผู้ดูแลย้ายคนในผังใหม่
         * แต่แผนกเดิมยังค้างอยู่ · เอกสารต้องตามผังจริง ไม่ใช่แผนกเดิม
         */
        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        $staleDepartment = Department::create(['name' => 'แผนกเดิมที่ย้ายออกไปแล้ว']);
        $division = $this->orgUnit('ฝ่ายวิชาการ');
        $group = $this->orgUnit('งานหลักสูตร', $division);

        $user = User::factory()->create([
            'org_unit_id' => $group,
            'department_id' => $staleDepartment->id,
        ]);
        $type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);

        $leave = Leave::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'total_days' => 2, 'reason' => 'ทดสอบ', 'status' => 'Pending',
        ]);

        $fields = app(LeaveDocumentFields::class)->build($leave->fresh(['user', 'type']));

        $this->assertSame('งานหลักสูตร', $fields['department']);
        $this->assertSame('ฝ่ายวิชาการ', $fields['division']);
        $this->assertNotSame($staleDepartment->name, $fields['department']);
    }

    public function test_ขอบเขตการมองเห็นไม่รั่วผ่านแผนกเดิมของผู้ที่มีหน่วยงานแล้ว(): void
    {
        /*
         * ช่องโหว่ที่ปิดในรอบนี้: ทางถอย `department_id` เดิมถูกใช้กับผู้ใช้ทุกคน
         * ผู้ใช้ที่อยู่นอกขอบเขตที่มองเห็นได้ แต่แผนกเดิม map เข้ามาในขอบเขตนั้น
         * จะโผล่ให้เห็นด้วย · ตอนนี้ทางถอยใช้ได้เฉพาะผู้ที่ยังไม่มี org_unit_id
         */
        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        $visibleUnit = $this->orgUnit('ฝ่ายที่มองเห็น');
        $hiddenUnit = $this->orgUnit('ฝ่ายที่ไม่ควรเห็น');

        $department = Department::create(['name' => 'แผนกเดิม']);
        // การสร้างแผนกทำให้ระบบผูก org_unit ให้เองอยู่แล้ว — ชี้ทับให้เป็นหน่วยที่ทดสอบ
        DB::table('department_org_unit_map')->updateOrInsert(
            ['department_id' => $department->id],
            ['org_unit_id' => $visibleUnit],
        );

        // อยู่คนละฝ่ายกับที่มองเห็นได้ แต่แผนกเดิมยังชี้เข้าฝ่ายที่มองเห็น
        $outsider = User::factory()->create(['org_unit_id' => $hiddenUnit, 'department_id' => $department->id]);
        $insider = User::factory()->create(['org_unit_id' => $visibleUnit]);
        $type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);

        foreach ([$outsider, $insider] as $owner) {
            Leave::create([
                'user_id' => $owner->id, 'leave_type_id' => $type->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDay()->toDateString(),
                'total_days' => 1, 'reason' => 'ทดสอบ', 'status' => 'Pending',
            ]);
        }

        $viewer = User::factory()->create(['org_unit_id' => $visibleUnit]);
        $scopes = $this->mock(OrgScopeResolver::class);
        $scopes->shouldReceive('visibleOrgUnitIds')->andReturn([$visibleUnit]);

        $ownerIds = app(LeaveVisibility::class)
            ->applyScope(Leave::query(), $viewer)
            ->pluck('user_id')
            ->all();

        $this->assertContains($insider->id, $ownerIds);
        $this->assertNotContains($outsider->id, $ownerIds, 'ผู้ที่มีหน่วยงานแล้วต้องไม่รั่วผ่านแผนกเดิม');
    }

    public function test_ผู้ใช้เก่าที่ยังไม่มีหน่วยงานยังมองเห็นได้ผ่านแผนกเดิม(): void
    {
        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        $visibleUnit = $this->orgUnit('ฝ่ายที่มองเห็น');
        $department = Department::create(['name' => 'แผนกเดิม']);
        // การสร้างแผนกทำให้ระบบผูก org_unit ให้เองอยู่แล้ว — ชี้ทับให้เป็นหน่วยที่ทดสอบ
        DB::table('department_org_unit_map')->updateOrInsert(
            ['department_id' => $department->id],
            ['org_unit_id' => $visibleUnit],
        );

        $legacy = User::factory()->create(['org_unit_id' => null, 'department_id' => $department->id]);
        $type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);
        Leave::create([
            'user_id' => $legacy->id, 'leave_type_id' => $type->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'total_days' => 1, 'reason' => 'ทดสอบ', 'status' => 'Pending',
        ]);

        $viewer = User::factory()->create(['org_unit_id' => $visibleUnit]);
        $scopes = $this->mock(OrgScopeResolver::class);
        $scopes->shouldReceive('visibleOrgUnitIds')->andReturn([$visibleUnit]);

        $ownerIds = app(LeaveVisibility::class)
            ->applyScope(Leave::query(), $viewer)
            ->pluck('user_id')
            ->all();

        $this->assertContains($legacy->id, $ownerIds);
    }
}
