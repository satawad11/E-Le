<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use App\Services\LeaveDocumentFields;
use App\Services\OrgScopeResolver;
use App\Services\SystemSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $department = OrgUnit::factory()->create(['name' => 'แผนกวิชาช่างยนต์']);
        $user = User::factory()->create(['org_unit_id' => $department->id]);

        $names = app(OrgScopeResolver::class)->homeOrgUnitNames($user->fresh());

        $this->assertSame('แผนกวิชาช่างยนต์', $names['unit']);
        $this->assertSame('แผนกวิชาช่างยนต์', $names['parent']);
    }

    public function test_ใบลาที่พิมพ์บอกหน่วยงานเดียวกับที่ระบบใช้เดินสายอนุมัติ(): void
    {
        // เอกสารต้องบอกหน่วยงานตามผังจริงชุดเดียวกับที่ระบบใช้หาผู้อนุมัติ
        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        $division = $this->orgUnit('ฝ่ายวิชาการ');
        $group = $this->orgUnit('งานหลักสูตร', $division);

        $user = User::factory()->create(['org_unit_id' => $group]);
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
    }

    public function test_โครงหน่วยงานชุดเดิมถูกถอดออกแล้ว(): void
    {
        /*
         * ด่านกันถอยกลับ: ถ้าตารางหรือคอลัมน์เหล่านี้กลับมา แปลว่ามีคนสร้างผังชุดที่สอง
         * ขึ้นใหม่ ซึ่งเป็นต้นเหตุของทั้งเอกสารที่บอกหน่วยงานผิด ขอบเขตการมองเห็นรั่ว
         * และปฏิทินวันทำงานเพี้ยน ที่ปิดไปในรอบนี้
         */
        $this->assertFalse(Schema::hasTable('departments'));
        $this->assertFalse(Schema::hasTable('department_org_unit_map'));
        $this->assertFalse(Schema::hasColumn('users', 'department_id'));
    }

    public function test_บันทึกชื่อสถานศึกษาแล้วยอดผังเปลี่ยนตาม(): void
    {
        /*
         * ORG-ROOT ถูกสร้างพร้อมชื่อจาก `app.name` ("Laravel" ในชุดทดสอบ) ซึ่งไหลไป
         * โผล่ในรายงานที่จัดกลุ่มตามผังได้ · ผู้ดูแลไม่ควรต้องรู้ว่ามีผังซ่อนอยู่ข้างหลัง
         */
        $this->assertSame(config('app.name'), DB::table('org_units')->where('code', 'ORG-ROOT')->value('name'));

        app(SystemSettingService::class)->updateMany(['institution_name' => 'วิทยาลัยเทคนิคทดสอบ']);

        $this->assertSame(
            'วิทยาลัยเทคนิคทดสอบ',
            DB::table('org_units')->where('code', 'ORG-ROOT')->value('name'),
        );
    }
}
