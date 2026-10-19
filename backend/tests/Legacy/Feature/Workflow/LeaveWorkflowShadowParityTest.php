<?php

namespace Tests\Legacy\Feature\Workflow;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Models\UserDelegation;
use App\Models\Workflow\WorkflowShadowComparison;
use App\Services\Workflow\LeaveWorkflowProvisioner;
use App\Services\Workflow\LeaveWorkflowShadow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LEGACY-05 · 05-3 — พิสูจน์ว่า engine ใหม่ให้ผลตรงกับตรรกะเดิม
 *
 * นี่คือหลักฐานหลักที่ใช้ตอบกฎข้อ 8 และ 9 ของเฟสนี้
 *
 * **เหตุผลที่ต้องมีเทสต์ชุดนี้:** ฐานข้อมูลจริงตอนนี้มีใบลาเพียง 3 ใบและถูก
 * soft delete ทั้งหมด การรัน replay บนข้อมูลจริงจึงพิสูจน์อะไรแทบไม่ได้เลย
 * เทสต์ชุดนี้จึงสร้างสถานะที่เป็นไปได้ทุกแบบขึ้นมาเทียบแทน — pending ทุกขั้น,
 * อนุมัติแล้ว, ปฏิเสธแล้ว, ยกเลิก, มีผู้รับมอบอำนาจ, และสายอนุมัติที่เปลี่ยนไป
 */
class LeaveWorkflowShadowParityTest extends TestCase
{
    use RefreshDatabase;

    private Role $hr;

    private Role $deputy;

    private Role $director;

    private LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = Role::firstOrCreate(['name' => 'HR Head', 'guard_name' => 'web']);
        $this->deputy = Role::firstOrCreate(['name' => 'Deputy Director', 'guard_name' => 'web']);
        $this->director = Role::firstOrCreate(['name' => 'Director', 'guard_name' => 'web']);

        ApprovalStep::create(['step_order' => 1, 'role_id' => $this->hr->id, 'name' => 'ขั้นที่ 1']);
        ApprovalStep::create(['step_order' => 2, 'role_id' => $this->deputy->id, 'name' => 'ขั้นที่ 2']);
        ApprovalStep::create(['step_order' => 3, 'role_id' => $this->director->id, 'name' => 'ขั้นที่ 3']);

        $this->leaveType = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);

        app(LeaveWorkflowProvisioner::class)->publishCurrentVersion();
    }

    private function shadow(): LeaveWorkflowShadow
    {
        return app(LeaveWorkflowShadow::class);
    }

    /**
     * สร้างใบลาพร้อม snapshot การอนุมัติแบบเดียวกับที่ LeaveService ทำจริง
     *
     * @param  array<int,string>  $stepStatuses  step_order => Pending|Approved|Rejected
     */
    private function makeLeave(string $status, int $approvalLevel, array $stepStatuses = []): Leave
    {
        $leave = Leave::create([
            'user_id' => User::factory()->create()->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'total_days' => 2,
            'reason' => 'ทดสอบ shadow',
            'status' => $status,
            'approval_level' => $approvalLevel,
        ]);

        foreach (ApprovalStep::orderBy('step_order')->get() as $step) {
            LeaveApproval::create([
                'leave_id' => $leave->id,
                'step_order' => $step->step_order,
                'step_name' => $step->name,
                'required_role_id' => $step->role_id,
                'status' => $stepStatuses[$step->step_order] ?? 'Pending',
            ]);
        }

        return $leave->fresh();
    }

    private function assertAllMatched(Leave $leave, string $context): void
    {
        $comparisons = $this->shadow()->compare($leave);

        $this->assertNotEmpty($comparisons, "ต้องมีผลเทียบสำหรับ {$context}");

        foreach ($comparisons as $comparison) {
            $this->assertTrue(
                $comparison->matched,
                sprintf(
                    "ผลไม่ตรงกันที่ %s [%s]\n  legacy = %s\n  engine = %s",
                    $context,
                    $comparison->checkpoint,
                    json_encode($comparison->legacy_result, JSON_UNESCAPED_UNICODE),
                    json_encode($comparison->engine_result, JSON_UNESCAPED_UNICODE),
                ),
            );
        }
    }

    // ------------------------------------------------------- ใบลาที่รออนุมัติ

    public function test_engine_matches_legacy_for_a_leave_waiting_at_the_first_step(): void
    {
        $this->assertAllMatched($this->makeLeave('Pending', 1), 'รออนุมัติขั้นที่ 1');
    }

    public function test_engine_matches_legacy_for_a_leave_waiting_at_the_middle_step(): void
    {
        $leave = $this->makeLeave('Pending', 2, [1 => 'Approved']);
        $this->assertAllMatched($leave, 'รออนุมัติขั้นที่ 2');
    }

    public function test_engine_matches_legacy_for_a_leave_waiting_at_the_final_step(): void
    {
        $leave = $this->makeLeave('Pending', 3, [1 => 'Approved', 2 => 'Approved']);
        $this->assertAllMatched($leave, 'รออนุมัติขั้นที่ 3');
    }

    // ------------------------------------------------------- ใบลาที่จบแล้ว

    public function test_engine_matches_legacy_for_an_approved_leave(): void
    {
        $leave = $this->makeLeave('Approved', 3, [1 => 'Approved', 2 => 'Approved', 3 => 'Approved']);
        $this->assertAllMatched($leave, 'อนุมัติครบแล้ว');
    }

    public function test_engine_matches_legacy_for_a_rejected_leave(): void
    {
        $leave = $this->makeLeave('Rejected', 2, [1 => 'Approved', 2 => 'Rejected']);
        $this->assertAllMatched($leave, 'ถูกปฏิเสธ');
    }

    public function test_engine_matches_legacy_for_a_cancelled_leave(): void
    {
        $leave = $this->makeLeave('Cancelled', 1);
        $this->assertAllMatched($leave, 'ถูกยกเลิก');
    }

    /**
     * Regression — mismatch จริงที่ shadow replay จับได้จากฐานข้อมูล
     *
     * ใบลาที่ถูกยกเลิก **ก่อนที่ใครจะอนุมัติสักขั้น** จะมี `leave_approvals`
     * เป็น Pending ครบทุกแถว ตรรกะเดิมตอบว่า "ไม่มีขั้นปัจจุบัน" เพราะดูสถานะ
     * ใบลาก่อน ส่วน engine รุ่นแรกตอบว่า "อยู่ขั้นที่ 1" เพราะดูแต่ task
     *
     * ถ้า cutover ไปตอนนั้น ใบลาที่ยกเลิกแล้วจะกลับมาโผล่ในคิวรออนุมัติ
     */
    public function test_a_leave_cancelled_before_any_approval_has_no_current_step(): void
    {
        User::factory()->create()->assignRole($this->hr->name);

        // ทุกขั้นยัง Pending — เหมือนใบลาใบที่ 3 ในฐานข้อมูลจริง
        $leave = $this->makeLeave('Cancelled', 1);

        $comparisons = collect($this->shadow()->compare($leave));

        $currentStep = $comparisons->firstWhere('checkpoint', WorkflowShadowComparison::CHECKPOINT_CURRENT_STEP);
        $approvers = $comparisons->firstWhere('checkpoint', WorkflowShadowComparison::CHECKPOINT_NEXT_APPROVER);

        $this->assertNull($currentStep->engine_result['step_order'], 'ใบลาที่ยกเลิกแล้วต้องไม่มีขั้นปัจจุบัน');
        $this->assertSame([], $approvers->engine_result['approver_ids'], 'ใบลาที่ยกเลิกแล้วต้องไม่มีผู้รออนุมัติ');
        $this->assertTrue($currentStep->matched);
        $this->assertTrue($approvers->matched);
    }

    // ------------------------------------------------------------ ผู้รับมอบอำนาจ

    public function test_engine_matches_legacy_when_a_delegation_is_in_force(): void
    {
        $hrHead = User::factory()->create();
        $hrHead->assignRole($this->hr->name);
        $delegate = User::factory()->create();

        UserDelegation::create([
            'user_id' => $hrHead->id,
            'delegate_id' => $delegate->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $this->assertAllMatched($this->makeLeave('Pending', 1), 'มีผู้รับมอบอำนาจ');
    }

    public function test_engine_matches_legacy_when_a_delegation_has_expired(): void
    {
        $hrHead = User::factory()->create();
        $hrHead->assignRole($this->hr->name);
        $delegate = User::factory()->create();

        UserDelegation::create([
            'user_id' => $hrHead->id,
            'delegate_id' => $delegate->id,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        $this->assertAllMatched($this->makeLeave('Pending', 1), 'ผู้รับมอบอำนาจหมดอายุ');
    }

    // ------------------------------------- ผู้ถือบทบาทหลายคน / ไม่มีใครถือเลย

    public function test_engine_matches_legacy_when_several_people_hold_the_step_role(): void
    {
        foreach (range(1, 3) as $ignored) {
            User::factory()->create()->assignRole($this->hr->name);
        }

        $this->assertAllMatched($this->makeLeave('Pending', 1), 'ผู้ถือบทบาทหลายคน');
    }

    public function test_engine_matches_legacy_when_nobody_holds_the_step_role(): void
    {
        // ไม่สร้างผู้ใช้ที่ถือบทบาทเลย — ทั้งสองฝั่งต้องบอกว่าไม่มีผู้อนุมัติเหมือนกัน
        $this->assertAllMatched($this->makeLeave('Pending', 1), 'ไม่มีผู้ถือบทบาท');
    }

    // --------------------------------------------- ทุกสถานะรวดเดียว + สรุปผล

    public function test_the_whole_matrix_of_states_produces_no_mismatch(): void
    {
        User::factory()->create()->assignRole($this->hr->name);
        User::factory()->create()->assignRole($this->deputy->name);
        User::factory()->create()->assignRole($this->director->name);

        $leaves = [
            $this->makeLeave('Pending', 1),
            $this->makeLeave('Pending', 2, [1 => 'Approved']),
            $this->makeLeave('Pending', 3, [1 => 'Approved', 2 => 'Approved']),
            $this->makeLeave('Approved', 3, [1 => 'Approved', 2 => 'Approved', 3 => 'Approved']),
            $this->makeLeave('Rejected', 1, [1 => 'Rejected']),
            $this->makeLeave('Cancelled', 1),
        ];

        foreach ($leaves as $leave) {
            $this->shadow()->compare($leave);
        }

        $total = WorkflowShadowComparison::count();
        $mismatched = WorkflowShadowComparison::where('matched', false)->count();

        $this->assertSame(count($leaves) * 3, $total, 'ต้องเทียบครบสามจุดต่อใบลาหนึ่งใบ');
        $this->assertSame(0, $mismatched, $this->describeMismatches());
    }

    /**
     * ใบลาที่ยื่นตอนสายอนุมัติเป็นแบบหนึ่ง ต้องไม่เปลี่ยนความหมายเมื่อสายอนุมัติถูกแก้
     *
     * เป็นข้อกำหนดหลักของเฟสนี้ที่ว่า "workflow definition เปลี่ยน
     * แต่ instance เก่าไม่เปลี่ยน"
     */
    public function test_changing_the_approval_chain_does_not_disturb_an_existing_leave(): void
    {
        User::factory()->create()->assignRole($this->hr->name);

        $leave = $this->makeLeave('Pending', 2, [1 => 'Approved']);
        $before = $this->shadow()->compare($leave);

        $beforeStep = collect($before)
            ->firstWhere('checkpoint', WorkflowShadowComparison::CHECKPOINT_CURRENT_STEP)
            ->engine_result['step_order'];

        // สายอนุมัติเปลี่ยนหลังจากใบลาใบนี้ถูกยื่นไปแล้ว
        ApprovalStep::where('step_order', 3)->delete();
        app(LeaveWorkflowProvisioner::class)->publishCurrentVersion();

        $leave->refresh();
        $after = $this->shadow()->compare($leave);

        $afterStep = collect($after)
            ->firstWhere('checkpoint', WorkflowShadowComparison::CHECKPOINT_CURRENT_STEP)
            ->engine_result['step_order'];

        $this->assertSame($beforeStep, $afterStep, 'ใบลาเดิมต้องอยู่ขั้นเดิมแม้สายอนุมัติจะเปลี่ยน');
        $this->assertSame(2, $afterStep);
    }

    /**
     * ประวัติการอนุมัติเดิมต้องไม่ถูกแตะแม้แต่แถวเดียว (กฎข้อ 2 และ 3)
     */
    public function test_shadow_mode_never_touches_the_legacy_approval_history(): void
    {
        User::factory()->create()->assignRole($this->hr->name);

        $leave = $this->makeLeave('Pending', 2, [1 => 'Approved']);

        $before = LeaveApproval::where('leave_id', $leave->id)
            ->orderBy('step_order')
            ->get(['step_order', 'status', 'approver_id', 'comment'])
            ->toArray();

        $beforeLeave = $leave->only(['status', 'approval_level']);

        $this->shadow()->compare($leave);
        $this->shadow()->compare($leave);

        $after = LeaveApproval::where('leave_id', $leave->id)
            ->orderBy('step_order')
            ->get(['step_order', 'status', 'approver_id', 'comment'])
            ->toArray();

        $this->assertSame($before, $after, 'shadow mode ต้องไม่แก้ leave_approvals');
        $this->assertSame($beforeLeave, $leave->fresh()->only(['status', 'approval_level']), 'shadow mode ต้องไม่แก้ใบลา');
        $this->assertSame(3, ApprovalStep::count(), 'shadow mode ต้องไม่แก้ approval_steps');
    }

    private function describeMismatches(): string
    {
        return WorkflowShadowComparison::where('matched', false)
            ->get()
            ->map(fn (WorkflowShadowComparison $row) => sprintf(
                'leave#%d [%s] legacy=%s engine=%s',
                $row->subject_id,
                $row->checkpoint,
                json_encode($row->legacy_result, JSON_UNESCAPED_UNICODE),
                json_encode($row->engine_result, JSON_UNESCAPED_UNICODE),
            ))
            ->implode("\n");
    }
}
