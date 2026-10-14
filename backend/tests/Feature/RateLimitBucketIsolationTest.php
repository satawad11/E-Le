<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-019 — แต่ละกลุ่มของ rate limit ต้องมีถังของตัวเอง
 *
 * `throttle:10,1` แบบ inline ตั้งกุญแจเป็น sha1(user id) เท่านั้น ทุก route
 * ที่ประกาศแบบนี้จึงใช้ถังเดียวกัน และถูกนับเพิ่มโดย throttle ของกลุ่มใหญ่ด้วย
 * ผลคือเรียก API ปกติไม่กี่ครั้งก็ทำให้ปุ่มพิมพ์ใบลาตอบ 429
 */
class RateLimitBucketIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private Leave $leave;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        $department = Department::create(['name' => 'ฝ่ายทดสอบ']);
        $type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);

        $this->employee = User::factory()->create(['department_id' => $department->id]);
        $this->employee->assignRole('Employee');

        $this->leave = Leave::create([
            'user_id' => $this->employee->id,
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'total_days' => 2,
            'reason' => 'ทดสอบ',
            'status' => 'Pending',
        ]);
    }

    public function test_normal_api_traffic_does_not_exhaust_the_document_download_limit(): void
    {
        $this->actingAs($this->employee);

        // เปิดหน้าเว็บธรรมดา ๆ ก็ยิง API ระดับนี้ได้สบายภายในนาทีเดียว
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/leaves')->assertOk();
        }

        $this->getJson("/api/leaves/{$this->leave->id}/pdf")->assertOk();
    }

    public function test_report_export_limit_does_not_block_printing_a_leave_form(): void
    {
        $this->actingAs($this->employee);

        for ($i = 0; $i < 12; $i++) {
            $this->getJson('/api/reports/export-pdf');
        }

        $this->getJson("/api/leaves/{$this->leave->id}/pdf")->assertOk();
    }

    public function test_document_downloads_are_still_rate_limited(): void
    {
        $this->actingAs($this->employee);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson("/api/leaves/{$this->leave->id}/pdf")->assertOk();
        }

        $this->getJson("/api/leaves/{$this->leave->id}/pdf")->assertStatus(429);
    }
}
