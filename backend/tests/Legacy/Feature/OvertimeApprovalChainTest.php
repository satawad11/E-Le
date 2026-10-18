<?php

namespace Tests\Legacy\Feature;

use App\Models\Overtime\OvertimeOrder;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow\WorkflowDefinition;
use App\Models\Workflow\WorkflowStep;
use App\Services\Workflow\OvertimeOrderWorkflowProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * สายคำสั่ง OT ตามที่เจ้าของระบบกำหนดเมื่อ 2026-08-19
 *
 *   หัวหน้าบุคลากร        ตั้งคำสั่ง
 *   รอง ผอ. ฝ่ายทรัพยากร  อนุญาต   (ขั้นที่ 1)
 *   ผู้อำนวยการ           อนุมัติ   (ขั้นที่ 2 จบสาย)
 *
 * กฎสำคัญที่ต้องไม่หลุด: คำสั่งใช้งานได้ก็ต่อเมื่อผ่านครบทั้งสองขั้น
 * ถ้าขั้นเดียวก็ใช้ได้ จะเท่ากับ ผอ. ถูกข้าม
 */
class OvertimeApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    private User $hrHead;

    private User $deputy;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->hrHead = $this->userWithRole('HR Head');
        $this->deputy = $this->userWithRole('Deputy Director');
        $this->director = $this->userWithRole('Director');
    }

    private function userWithRole(string $role): User
    {
        return User::whereHas('roles', fn ($query) => $query->where('name', $role))
            ->whereNotNull('org_unit_id')
            ->firstOrFail();
    }

    private function issueOrder(): OvertimeOrder
    {
        $response = $this->actingAs($this->hrHead)->postJson('/api/overtime/orders', [
            'title' => 'ปฏิบัติงานนอกเวลาราชการเพื่อจัดทำบัญชีถือจ่าย',
            'scheduled_start' => '2026-09-10 17:00:00',
            'scheduled_end' => '2026-09-10 20:00:00',
        ])->assertCreated();

        return OvertimeOrder::where('uuid', $response->json('order.uuid'))->firstOrFail();
    }

    public function test_the_workflow_has_two_role_bound_steps_in_the_prescribed_order(): void
    {
        $this->issueOrder();

        $definition = WorkflowDefinition::where('code', OvertimeOrderWorkflowProvisioner::DEFINITION_CODE)->firstOrFail();
        $steps = $definition->activeVersion()->steps()->orderBy('step_order')->get();

        $this->assertCount(2, $steps);
        $this->assertSame('overtime_permit', $steps[0]->code);
        $this->assertSame('overtime_approval', $steps[1]->code);
        $this->assertFalse((bool) $steps[0]->is_final);
        $this->assertTrue((bool) $steps[1]->is_final);

        foreach ($steps as $step) {
            $this->assertSame(WorkflowStep::ASSIGNEE_ROLE, $step->assignee_type);
        }

        $this->assertSame(
            'Deputy Director',
            $steps[0]->assigneeRole?->name ?? Role::find($steps[0]->assignee_role_id)?->name
        );
        $this->assertSame(
            'Director',
            $steps[1]->assigneeRole?->name ?? Role::find($steps[1]->assignee_role_id)?->name
        );
    }

    public function test_hr_head_issues_deputy_permits_and_only_the_director_makes_it_usable(): void
    {
        $order = $this->issueOrder();
        $this->assertSame('pending', $order->status);

        $this->actingAs($this->deputy)
            ->postJson("/api/overtime/orders/{$order->uuid}/approve", ['comment' => 'อนุญาตตามคำขอ'])
            ->assertOk()
            ->assertJsonPath('message', 'บันทึกการอนุญาตแล้ว รอผู้อำนวยการอนุมัติ');

        $this->assertSame('pending', $order->fresh()->status, 'ขั้นอนุญาตต้องยังไม่ทำให้คำสั่งใช้งานได้');

        $this->actingAs($this->director)
            ->postJson("/api/overtime/orders/{$order->uuid}/approve", ['comment' => 'อนุมัติ'])
            ->assertOk()
            ->assertJsonPath('message', 'อนุมัติคำสั่ง OT แล้ว');

        $this->assertSame('approved', $order->fresh()->status);
    }

    /**
     * ผอ. ข้ามขั้นอนุญาตไม่ได้ — งานยังเป็นของรอง ผอ. อยู่
     */
    public function test_the_director_cannot_act_before_the_deputy_has_permitted(): void
    {
        $order = $this->issueOrder();

        $this->actingAs($this->director)
            ->postJson("/api/overtime/orders/{$order->uuid}/approve", ['comment' => 'ขอข้ามขั้น'])
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);
    }

    /**
     * หัวหน้าบุคลากรตั้งคำสั่งได้ แต่ต้องไม่อนุมัติคำสั่งของตนเอง
     */
    public function test_the_issuer_cannot_decide_their_own_order(): void
    {
        $order = $this->issueOrder();

        $this->actingAs($this->hrHead)
            ->postJson("/api/overtime/orders/{$order->uuid}/approve", ['comment' => 'อนุมัติเอง'])
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);
    }

    /**
     * ไม่อนุญาตตั้งแต่ขั้นแรก = จบสายทันที ไม่ต้องรอ ผอ.
     */
    public function test_a_refusal_at_the_first_step_ends_the_chain(): void
    {
        $order = $this->issueOrder();

        $this->actingAs($this->deputy)
            ->postJson("/api/overtime/orders/{$order->uuid}/reject", ['comment' => 'งบประมาณไม่เพียงพอ'])
            ->assertOk();

        $this->assertSame('rejected', $order->fresh()->status);

        $this->actingAs($this->director)
            ->postJson("/api/overtime/orders/{$order->uuid}/approve", ['comment' => 'ขออนุมัติย้อนหลัง'])
            ->assertStatus(422);

        $this->assertSame('rejected', $order->fresh()->status);
    }
}
