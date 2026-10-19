<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use App\Services\LeaveEligibilityRuleService;
use App\Services\Time\TimeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * REDESIGN-2569 · D-02 — ลากิจส่วนตัวเพื่อเลี้ยงดูบุตร ลาได้เฉพาะต่อจากวันสุดท้ายของลาคลอดบุตร
 */
class ChildcareLeaveTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $maternity;

    private LeaveType $childcare;

    private User $mother;

    protected function setUp(): void
    {
        parent::setUp();

        $this->maternity = LeaveType::where('name', 'ลาคลอดบุตร')->firstOrFail();
        $this->childcare = LeaveType::where('name', 'ลากิจส่วนตัวเพื่อเลี้ยงดูบุตร')->firstOrFail();
        $this->mother = User::factory()->create();
        app(TimeService::class)->flush();
    }

    private function maternityLeave(string $end, string $status = 'Approved'): Leave
    {
        return Leave::create([
            'user_id' => $this->mother->id, 'leave_type_id' => $this->maternity->id,
            'start_date' => Carbon::parse($end)->subDays(89)->toDateString(), 'end_date' => $end,
            'total_days' => 90, 'reason' => 'คลอดบุตร', 'status' => $status,
        ]);
    }

    /** นโยบายทดสอบที่มีเฉพาะกฎลาต่อเนื่อง (ไม่ผูกเพศ เพื่อทดสอบกฎนี้อย่างเดียว) */
    private function policy(): LeavePolicy
    {
        return new LeavePolicy(['leave_type_id' => $this->childcare->id, 'eligibility_rules' => ['requires_predecessor_leave_type_id' => $this->maternity->id]]);
    }

    private function assertRejected(string $start, string $field, string $contains): void
    {
        try {
            app(LeaveEligibilityRuleService::class)->assert($this->mother, $this->policy(), Carbon::parse($start));
            $this->fail('ต้องปฏิเสธการลาเลี้ยงดูบุตรครั้งนี้');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($contains, $e->errors()[$field][0]);
        }
    }

    public function test_the_leave_type_is_created_unpaid_with_zero_quota_until_the_owner_sets_days(): void
    {
        $policies = LeavePolicy::where('leave_type_id', $this->childcare->id)->get();

        $this->assertSame(PersonnelType::where('is_active', true)->count(), $policies->count(), 'ทุกประเภทบุคลากรต้องมีนโยบาย');
        foreach ($policies as $policy) {
            $this->assertSame(0.0, (float) $policy->annual_quota);
            $this->assertSame(0.0, (float) $policy->max_days_with_pay, 'ไม่ได้รับเงินเดือนระหว่างลา');
            $this->assertSame($this->maternity->id, $policy->eligibility_rules['requires_predecessor_leave_type_id']);
        }
        $this->assertSame('ลก.', $this->childcare->code_prefix);
    }

    public function test_starting_the_working_day_after_maternity_ends_is_allowed(): void
    {
        $this->maternityLeave('2030-03-15'); // วันศุกร์

        app(LeaveEligibilityRuleService::class)->assert($this->mother, $this->policy(), Carbon::parse('2030-03-16')); // เสาร์
        app(LeaveEligibilityRuleService::class)->assert($this->mother, $this->policy(), Carbon::parse('2030-03-18')); // จันทร์ — คั่นด้วยวันหยุดเท่านั้น
        $this->addToAssertionCount(2);
    }

    public function test_a_working_day_gap_after_maternity_is_rejected(): void
    {
        $this->maternityLeave('2030-03-15');

        $this->assertRejected('2030-03-19', 'start_date', 'โดยไม่มีวันทำงานคั่น'); // อังคาร — จันทร์เป็นวันทำงาน
    }

    public function test_without_an_approved_maternity_leave_it_is_rejected(): void
    {
        $this->assertRejected('2030-03-18', 'leave_type_id', 'ต้องลาต่อเนื่องจากลาคลอดบุตร');

        $this->maternityLeave('2030-03-15', 'Pending');
        $this->assertRejected('2030-03-18', 'leave_type_id', 'ที่ได้รับอนุญาตแล้ว');
    }

    public function test_it_cannot_start_during_maternity_leave(): void
    {
        $this->maternityLeave('2030-03-15');

        $this->assertRejected('2030-03-15', 'leave_type_id', 'ต้องลาต่อเนื่องจากลาคลอดบุตร');
    }
}
