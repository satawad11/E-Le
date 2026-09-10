<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\LeaveDocumentFields;
use App\Services\LeaveDocumentTemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * แบบฟอร์มใบลาต้องเลือกจาก "ค่าที่ตั้งไว้" ไม่ใช่จากการเดาชื่อไทยของประเภทการลา
 * และค่าที่เติมลงช่องว่างต้องมาจากหน้าตั้งค่าระบบ ไม่ใช่ค่าที่เขียนตายในโค้ด
 */
class LeaveFormBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        $department = OrgUnit::factory()->create(['name' => 'แผนกวิชาช่างยนต์']);

        $this->employee = User::factory()->create(['org_unit_id' => $department->id]);
        $this->employee->assignRole('Employee');
    }

    private function leaveFor(LeaveType $type): Leave
    {
        return Leave::create([
            'user_id' => $this->employee->id,
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'total_days' => 2,
            'reason' => 'ทดสอบ',
            'status' => 'Pending',
        ]);
    }

    public function test_the_form_follows_the_configured_template_not_the_thai_type_name(): void
    {
        // ชื่อประเภทการลาไม่มีคำว่า "พักผ่อน" เลย แต่ตั้งค่าให้ใช้แบบฟอร์มลาพักผ่อน
        $type = LeaveType::create([
            'name' => 'ลาประจำปีของสถานศึกษา',
            'max_days' => 10,
            'document_template_code' => 'leave_vacation',
        ]);

        $leave = $this->leaveFor($type);
        $resolver = app(LeaveDocumentTemplateResolver::class);

        $this->assertSame('leave_vacation', $resolver->templateCodeForLeave($leave));
        $this->assertSame('pdf.leave_vacation', $resolver->viewForCode($resolver->templateCodeForLeave($leave)));
    }

    public function test_an_unconfigured_type_still_falls_back_to_the_old_name_matching(): void
    {
        $type = LeaveType::create(['name' => 'ลาพักผ่อน', 'max_days' => 10]);
        $type->forceFill(['document_template_code' => null])->save();

        $resolver = app(LeaveDocumentTemplateResolver::class);

        $this->assertSame('leave_vacation', $resolver->templateCodeForLeave($this->leaveFor($type)));
    }

    public function test_organisation_names_come_from_settings(): void
    {
        Setting::updateOrCreate(['key' => 'institution_name'], ['value' => 'วิทยาลัยทดสอบระบบ']);
        Setting::updateOrCreate(['key' => 'director_position'], ['value' => 'ผู้อำนวยการวิทยาลัยทดสอบระบบ']);

        $type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30, 'document_template_code' => 'leave_sick_personal']);
        $fields = app(LeaveDocumentFields::class)->build($this->leaveFor($type));

        $this->assertSame('วิทยาลัยทดสอบระบบ', $fields['school_name']);
        $this->assertSame('ผู้อำนวยการวิทยาลัยทดสอบระบบ', $fields['director_position']);
        $this->assertSame('วิทยาลัยทดสอบระบบ', $fields['written_at']);
        $this->assertSame('แผนกวิชาช่างยนต์', $fields['division']);
    }

    public function test_extra_fields_defined_by_an_admin_reach_the_printed_form(): void
    {
        $type = LeaveType::create([
            'name' => 'ลาอุปสมบท',
            'max_days' => 120,
            'document_template_code' => 'leave_ordination',
            'extra_fields' => [
                ['key' => 'ordination_temple', 'label' => 'วัดที่อุปสมบท', 'type' => 'text', 'required' => true],
            ],
        ]);

        $leave = $this->leaveFor($type);
        $leave->update(['meta_data' => ['extra_fields' => ['ordination_temple' => 'วัดทดสอบ']]]);

        $fields = app(LeaveDocumentFields::class)->build($leave->fresh());

        $this->assertSame('วัดทดสอบ', $fields['extra']['ordination_temple']);
    }

    public function test_the_statistics_table_counts_days_and_occurrences(): void
    {
        $type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30, 'document_template_code' => 'leave_sick_personal']);

        Leave::create([
            'user_id' => $this->employee->id,
            'leave_type_id' => $type->id,
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDays(9)->toDateString(),
            'total_days' => 2,
            'reason' => 'ครั้งก่อน',
            'status' => 'Approved',
        ]);

        $current = $this->leaveFor($type);
        $fields = app(LeaveDocumentFields::class)->build($current);

        $this->assertSame(2, $fields['stats']['ลาป่วย']['before']);
        $this->assertSame(2, $fields['stats']['ลาป่วย']['current']);
        $this->assertSame(4, $fields['stats']['ลาป่วย']['total']);
        $this->assertSame(1, $fields['stats']['ลาป่วย']['times_before']);
    }
}
