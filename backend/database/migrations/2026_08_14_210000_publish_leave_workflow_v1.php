<?php

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\UserDelegation;
use App\Models\Workflow\WorkflowDelegation;
use App\Services\Workflow\LeaveWorkflowProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-05 · 05-2 — ออก "Leave Workflow v1" จากสายอนุมัติที่ใช้อยู่จริง
 * และสร้าง shadow instance ให้ใบลาที่มีอยู่แล้วทุกใบ
 *
 * **ไม่แตะข้อมูลเดิมแม้แต่แถวเดียว**
 *   - `approval_steps` อ่านอย่างเดียว (กฎข้อ 1)
 *   - `leave_approvals` อ่านอย่างเดียว (กฎข้อ 2, 3)
 *   - `leaves` ไม่ถูก update เลย — สถานะและ `approval_level` คงเดิมทั้งหมด (กฎข้อ 4)
 *
 * instance ที่สร้างเป็น shadow ทั้งหมด ยังไม่มีผลต่อการตัดสินใด ๆ (กฎข้อ 5, 7)
 *
 * ถ้าติดตั้งนี้ยังไม่มี `approval_steps` (เช่น ฐานข้อมูลว่างในชุดทดสอบ)
 * migration จะข้ามไปเงียบ ๆ แล้วให้ seeder จัดการตอนมีข้อมูลจริง
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('approval_steps') || ! ApprovalStep::exists()) {
            return;
        }

        $provisioner = app(LeaveWorkflowProvisioner::class);
        $provisioner->publishCurrentVersion();

        // สะท้อนการมอบอำนาจที่มีอยู่เข้า workflow_delegations (ของเดิมยังเป็นตัวจริง)
        $this->mirrorDelegations();

        // สร้าง instance ให้ใบลาที่มีประวัติการอนุมัติอยู่แล้ว
        Leave::query()->orderBy('id')->chunkById(200, function ($leaves) use ($provisioner): void {
            foreach ($leaves as $leave) {
                $provisioner->backfill($leave);
            }
        });
    }

    public function down(): void
    {
        /*
         * ไม่ถอนอะไร — ตารางทั้งชุดถูกลบโดย migration ที่สร้างมันอยู่แล้ว
         * และไม่มีข้อมูลเดิมใดถูกแก้ในขานี้จึงไม่มีอะไรต้องคืน
         */
    }

    private function mirrorDelegations(): void
    {
        if (! Schema::hasTable('user_delegations')) {
            return;
        }

        foreach (UserDelegation::query()->orderBy('id')->get() as $legacy) {
            WorkflowDelegation::firstOrCreate(
                ['legacy_delegation_id' => $legacy->id],
                [
                    'delegator_id' => $legacy->user_id,
                    'delegate_id' => $legacy->delegate_id,
                    // ไม่ระบุ workflow และไม่ระบุบทบาท = ขอบเขตเท่าของเดิมทุกประการ
                    'workflow_definition_id' => null,
                    'scope_role_id' => null,
                    'delegation_type' => WorkflowDelegation::TYPE_MANAGER_DELEGATE,
                    'effective_from' => $legacy->start_date,
                    'effective_to' => $legacy->end_date,
                    'is_active' => $legacy->is_active,
                    'reason' => 'mirror จาก user_delegations #'.$legacy->id.' (LEGACY-05)',
                ],
            );
        }
    }
};
