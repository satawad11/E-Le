<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $employee;

    protected LeaveType $leaveType;

    protected Leave $leave;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        $dept = OrgUnit::factory()->create(['name' => 'ทดสอบ']);

        $this->employee = User::factory()->create([
            'org_unit_id' => $dept->id,
        ]);
        $this->employee->assignRole('Employee');

        $this->leaveType = LeaveType::create([
            'name' => 'ลาป่วย',
            'max_days' => 30,
        ]);

        LeaveBalance::create([
            'user_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'year' => now()->year,
            'quota' => 30,
            'used' => 0,
        ]);

        $this->leave = Leave::create([
            'user_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'total_days' => 2,
            'reason' => 'ทดสอบลาป่วย',
            'status' => 'Pending',
        ]);
    }

    public function test_user_can_download_leave_pdf(): void
    {
        $response = $this->actingAs($this->employee)
            ->getJson("/api/leaves/{$this->leave->id}/pdf");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_user_can_download_leave_word_docx(): void
    {
        $response = $this->actingAs($this->employee)
            ->getJson("/api/leaves/{$this->leave->id}/word");

        $response->assertStatus(200);
    }

    public function test_user_can_download_leave_cancel_pdf(): void
    {
        // แบบฟอร์มใบยกเลิกมีให้พิมพ์ก็ต่อเมื่อเคยยื่นคำขอยกเลิกจริง
        $this->leave->update(['cancellation_status' => 'Pending', 'cancellation_reason' => 'ติดภารกิจ']);

        $response = $this->actingAs($this->employee)
            ->getJson("/api/leaves/{$this->leave->id}/pdf?form=cancel");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }
}
