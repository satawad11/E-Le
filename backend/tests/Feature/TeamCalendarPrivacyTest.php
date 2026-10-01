<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ปฏิทินวันลาทีม (GET /api/leaves/team-calendar) แสดงใบลาของเพื่อนร่วมหน่วยงาน
 * จึงต้องเปิดเผยเพียงชื่อผู้ลา/ประเภท/ช่วงวัน/สถานะ — ห้ามรั่วข้อมูลอ่อนไหว
 * (เหตุผลการลา, ที่อยู่ติดต่อ, เหตุผลการยกเลิก, meta_data) ที่ LeavePolicy::view
 * ปกป้องไว้ เทสต์นี้ล็อกสัญญานั้นไว้ไม่ให้ endpoint คืน model เต็มอีก
 */
class TeamCalendarPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $dept;

    private LeaveType $type;

    private Leave $leave;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = OrgUnit::factory()->create(['name' => 'ฝ่าย ก']);
        $this->type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);

        $owner = User::factory()->create([
            'org_unit_id' => $this->dept->id,
            'name' => 'สมชาย ใจดี',
        ]);

        $this->leave = Leave::create([
            'user_id' => $owner->id,
            'leave_type_id' => $this->type->id,
            'start_date' => now()->startOfYear()->addDays(30)->toDateString(),
            'end_date' => now()->startOfYear()->addDays(31)->toDateString(),
            'total_days' => 2,
            'leave_period' => 'full_day',
            'reason' => 'ป่วยเป็นโรคที่เป็นความลับส่วนบุคคล',
            'contact_address' => '99/9 หมู่บ้านลับ ต.ในเมือง',
            'status' => 'Approved',
        ]);
    }

    private function coworker(): User
    {
        // ผู้ใช้ทั่วไปในหน่วยงานเดียวกัน ไม่มีสิทธิ์ attendance.view_all
        return User::factory()->create(['org_unit_id' => $this->dept->id])->fresh();
    }

    public function test_coworker_sees_the_leave_on_the_team_calendar(): void
    {
        $response = $this->actingAs($this->coworker())->getJson('/api/leaves/team-calendar');

        $response->assertOk();
        $ids = array_map(fn ($row) => $row['id'], $response->json());

        $this->assertContains($this->leave->id, $ids, 'เพื่อนร่วมหน่วยงานต้องเห็นใบลาบนปฏิทินทีม');
    }

    public function test_calendar_exposes_only_display_fields(): void
    {
        $response = $this->actingAs($this->coworker())->getJson('/api/leaves/team-calendar');

        $response->assertOk();
        $row = collect($response->json())->firstWhere('id', $this->leave->id);

        $this->assertNotNull($row);
        // field ที่ปฏิทินต้องใช้จริง
        $this->assertSame('Approved', $row['status']);
        $this->assertSame('สมชาย ใจดี', $row['user']['name']);
        $this->assertSame('ลาป่วย', $row['type']['name']);
        $this->assertArrayHasKey('start_date', $row);
        $this->assertArrayHasKey('end_date', $row);

        // ต้องไม่มีข้อมูลอ่อนไหวหลุดออกมาเลย
        $this->assertArrayNotHasKey('reason', $row);
        $this->assertArrayNotHasKey('contact_address', $row);
        $this->assertArrayNotHasKey('cancellation_reason', $row);
        $this->assertArrayNotHasKey('meta_data', $row);
    }

    public function test_sensitive_values_never_appear_anywhere_in_the_payload(): void
    {
        $response = $this->actingAs($this->coworker())->getJson('/api/leaves/team-calendar');

        $response->assertOk();
        $raw = $response->getContent();

        $this->assertStringNotContainsString('ป่วยเป็นโรคที่เป็นความลับส่วนบุคคล', $raw);
        $this->assertStringNotContainsString('หมู่บ้านลับ', $raw);
    }
}
