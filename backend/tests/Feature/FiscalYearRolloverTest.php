<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\PersonnelType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ขึ้นรอบปีงบประมาณใหม่ต้องเลื่อนปีปัจจุบันและมีสิทธิ์วันลาให้ทุกคนทันที
 *
 * ครอบข้อ 02 · 03 · 14 ของผลตรวจโมดูลลาและการลงเวลา
 */
class FiscalYearRolloverTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private PersonnelType $personnelType;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\FiscalYearSeeder', '--force' => true]);

        $department = OrgUnit::factory()->create(['name' => 'ฝ่ายทดสอบรอบปี']);
        $this->personnelType = PersonnelType::firstOrCreate(['code' => 'civil_servant'], ['name' => 'ข้าราชการ']);
        $this->employee = User::factory()->create([
            'org_unit_id' => $department->id,
            'personnel_type_id' => $this->personnelType->id,
            'is_active' => true,
        ]);
    }

    /*
     * ข้อ 02 — ธง `is_current` มีแค่ seeder ตอนติดตั้งกับหน้าตั้งค่าที่ผู้ดูแลกดเองเป็นคนเขียน
     * หลัง 30 ก.ย. ระบบจึงยังคิดว่าเป็นปีงบประมาณเดิมตลอดไป
     */
    public function test_the_command_moves_the_current_fiscal_year_forward(): void
    {
        $next = FiscalYear::where('is_current', false)->orderBy('year')->where('year', '>', FiscalYear::current()->year)->firstOrFail();

        Artisan::call('leave:open-fiscal-year', ['--year' => $next->year]);

        $this->assertTrue($next->fresh()->is_current);
        $this->assertSame(1, FiscalYear::where('is_current', true)->count(), 'ต้องมีปีปัจจุบันเพียงปีเดียวเสมอ');
    }

    /*
     * ข้อ 03 — ไม่มีอะไรสร้างแถวโควตาของปีใหม่ให้ทุกคน หน้า "วันลาคงเหลือ" จึงว่างเปล่า
     */
    public function test_opening_a_year_provisions_a_quota_row_for_every_leave_type(): void
    {
        $types = collect(['ลาป่วย' => 60.0, 'ลากิจส่วนตัว' => 45.0, 'ลาพักผ่อน' => 10.0])
            ->map(function (float $quota, string $name) {
                $type = LeaveType::create(['name' => $name, 'max_days' => (int) $quota]);
                LeavePolicy::create([
                    'personnel_type_id' => $this->personnelType->id,
                    'leave_type_id' => $type->id,
                    'effective_from' => now()->subYear()->startOfYear(),
                    'annual_quota' => $quota,
                    'period_basis' => 'fiscal_year',
                    'is_active' => true,
                ]);

                return $type;
            });

        $next = FiscalYear::where('year', '>', FiscalYear::current()->year)->orderBy('year')->firstOrFail();
        $yearKey = (int) $next->year - 543;

        $this->assertSame(0, LeaveBalance::where('year', $yearKey)->count());

        Artisan::call('leave:open-fiscal-year', ['--year' => $next->year]);

        foreach ($types as $type) {
            $this->assertDatabaseHas('leave_balances', [
                'user_id' => $this->employee->id,
                'leave_type_id' => $type->id,
                'year' => $yearKey,
            ]);
        }

        $this->assertSame(
            10.0,
            (float) LeaveBalance::where('year', $yearKey)->where('leave_type_id', $types['ลาพักผ่อน']->id)->value('quota'),
            'โควตาต้องมาจากนโยบายสิทธิการลา ไม่ใช่ leave_types.max_days',
        );
    }

    public function test_running_the_command_twice_does_not_duplicate_or_reset_anything(): void
    {
        $type = LeaveType::create(['name' => 'ลาพักผ่อน', 'max_days' => 10]);
        LeavePolicy::create([
            'personnel_type_id' => $this->personnelType->id,
            'leave_type_id' => $type->id,
            'effective_from' => now()->subYear()->startOfYear(),
            'annual_quota' => 10,
            'period_basis' => 'fiscal_year',
            'is_active' => true,
        ]);

        $next = FiscalYear::where('year', '>', FiscalYear::current()->year)->orderBy('year')->firstOrFail();
        $yearKey = (int) $next->year - 543;

        Artisan::call('leave:open-fiscal-year', ['--year' => $next->year]);

        $balance = LeaveBalance::where('year', $yearKey)->where('leave_type_id', $type->id)->firstOrFail();
        $balance->update(['used' => 3, 'carried_forward' => 5]);

        $before = LeaveBalance::where('year', $yearKey)->count();

        Artisan::call('leave:open-fiscal-year', ['--year' => $next->year]);

        $this->assertSame($before, LeaveBalance::where('year', $yearKey)->count(), 'รันซ้ำต้องไม่สร้างแถวซ้ำ');
        $this->assertSame(3.0, (float) $balance->fresh()->used, 'ต้องไม่ล้างวันที่ใช้ไปแล้ว');
        $this->assertSame(5.0, (float) $balance->fresh()->carried_forward, 'ต้องไม่ล้างวันสะสมที่ทบมา');
    }

    /*
     * ข้อ 14 — นโยบายที่นับตามปีปฏิทินคีย์แถวด้วยปีปฏิทิน ไม่ใช่ปีงบประมาณ
     * คำสั่งเปิดรอบปีงบประมาณจึงต้องไม่ไปสร้างแถวผิดปีให้
     */
    public function test_calendar_year_policies_are_left_to_their_own_cycle(): void
    {
        $type = LeaveType::create(['name' => 'ลานับตามปีปฏิทิน', 'max_days' => 5]);
        LeavePolicy::create([
            'personnel_type_id' => $this->personnelType->id,
            'leave_type_id' => $type->id,
            'effective_from' => now()->subYear()->startOfYear(),
            'annual_quota' => 5,
            'period_basis' => 'calendar_year',
            'is_active' => true,
        ]);

        $next = FiscalYear::where('year', '>', FiscalYear::current()->year)->orderBy('year')->firstOrFail();

        Artisan::call('leave:open-fiscal-year', ['--year' => $next->year]);

        $this->assertDatabaseMissing('leave_balances', [
            'leave_type_id' => $type->id,
            'year' => (int) $next->year - 543,
        ]);
    }
}
