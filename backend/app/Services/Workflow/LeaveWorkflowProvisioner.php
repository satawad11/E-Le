<?php

namespace App\Services\Workflow;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\Workflow\WorkflowDefinition;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowStep;
use App\Models\Workflow\WorkflowTask;
use App\Models\Workflow\WorkflowTransition;
use App\Models\Workflow\WorkflowVersion;
use Illuminate\Support\Facades\DB;

/**
 * LEGACY-05 · 05-2 — แปลงสายอนุมัติใบลาปัจจุบันเป็น "Leave Workflow v1"
 *
 * แหล่งที่มาของเวอร์ชันคือ `approval_steps` ที่ผู้ดูแลตั้งไว้จริง ณ วันที่ออกเวอร์ชัน
 * — หน้าจอตั้งสายอนุมัติยังเป็นหน้าเดิม ผู้ดูแลไม่ต้องเรียนรู้อะไรใหม่ และการออก
 * เวอร์ชันเป็นผลพลอยได้ของการกดบันทึก
 *
 * `approval_steps` ไม่ใช่ของเก่าที่รอถูกถอด — เป็นหน้าจอตั้งสายอนุมัติถาวรของระบบ
 * ตาม ADR-APPROVAL-CHAIN-EDITOR-20260912 · ส่วนอีกสองตารางถูกเขียนคู่กันไปเสมอ
 * (dual-write) เพื่อเป็นทางถอยถ้าต้องปิดสวิตช์ engine:
 *   - `leave_approvals`       ประวัติรายขั้นของใบลาแต่ละใบ และเป็น snapshot ที่
 *                             engine ใช้ตัดสินว่าใครมีสิทธิ์ในขั้นนั้น
 *   - `leaves.approval_level` ขั้นปัจจุบัน ใช้โดยรายงานและแบบฟอร์มราชการ
 *
 * instance ที่สร้างจากที่นี่เป็นตัวที่การอนุมัติจริงเดินอยู่ ตั้งแต่ cutover (CLEANUP-01 · D4)
 */
class LeaveWorkflowProvisioner
{
    public const DEFINITION_CODE = 'leave.approval';

    public function __construct(protected readonly WorkflowEngine $engine) {}

    /*
     * ─────────── จุดที่ "สายขอลา" กับ "สายขอยกเลิก" ต่างกัน (CLEANUP-02 · D4) ───────────
     *
     * ทั้งสองสายเดินบนโครงเดียวกันทุกประการ ต่างกันแค่ชุดขั้นตอนต้นทาง, แถวสรุปที่อ่าน
     * และคอลัมน์ที่บอกว่าตอนนี้อยู่ขั้นไหน — แยกออกมาเป็นเมธอดเพื่อให้
     * `LeaveCancellationWorkflowProvisioner` เปลี่ยนเฉพาะส่วนนี้ได้โดยไม่คัดลอกโค้ดทั้งชุด
     */

    protected function definitionCode(): string
    {
        return self::DEFINITION_CODE;
    }

    protected function definitionName(): string
    {
        return 'สายการอนุมัติใบลา';
    }

    protected function definitionDescription(): string
    {
        return 'แปลงจาก approval_steps ที่ใช้งานอยู่เดิม (LEGACY-05 · 05-2)';
    }

    protected function legacyChain(): string
    {
        return ApprovalStep::CHAIN_LEAVE;
    }

    protected function approvalStage(): string
    {
        return LeaveApproval::STAGE_REQUEST;
    }

    /** ขั้นที่ใบลานี้ค้างอยู่ในสายนี้ */
    protected function currentLevel(Leave $leave): ?int
    {
        return $leave->approval_level;
    }

    /** สถานะของ "เรื่อง" ในสายนี้ ใช้ตัดสินสถานะของ instance */
    protected function subjectStatus(Leave $leave): ?string
    {
        return $leave->status;
    }

    /**
     * สร้าง/หา definition ของสายอนุมัติใบลา
     */
    public function definition(): WorkflowDefinition
    {
        return WorkflowDefinition::firstOrCreate(
            ['code' => $this->definitionCode()],
            [
                'module' => 'leave',
                'name' => $this->definitionName(),
                'subject_type' => (new Leave)->getMorphClass(),
                'description' => $this->definitionDescription(),
                'is_active' => true,
            ],
        );
    }

    /**
     * ออกเวอร์ชันจากสายอนุมัติที่ใช้อยู่ตอนนี้
     *
     * idempotent: ถ้ามีเวอร์ชัน active ที่ขั้นตอนตรงกับ `approval_steps` อยู่แล้ว
     * จะคืนตัวเดิม ไม่ออกเวอร์ชันใหม่ซ้ำ ๆ ทุกครั้งที่รัน
     *
     * ถ้าสายอนุมัติเปลี่ยนไปจากเวอร์ชันล่าสุด จะ **ออกเวอร์ชันใหม่** และปลดเวอร์ชันเก่า
     * โดยไม่แตะ instance ที่ pin เวอร์ชันเก่าไว้ — นี่คือหัวใจของข้อกำหนด
     * "แก้ workflow แล้วรายการเก่าต้องไม่เปลี่ยน"
     */
    public function publishCurrentVersion(): WorkflowVersion
    {
        $definition = $this->definition();
        $legacySteps = ApprovalStep::stepsFor($this->legacyChain());

        if ($legacySteps->isEmpty()) {
            throw new \RuntimeException('ยังไม่ได้ตั้งค่าลำดับการอนุมัติใน approval_steps จึงแปลงเป็น workflow ไม่ได้');
        }

        $fingerprint = $this->fingerprintOf($legacySteps);
        $active = $definition->activeVersion();

        if ($active !== null && $this->fingerprintOfVersion($active) === $fingerprint) {
            return $active;
        }

        return DB::transaction(function () use ($definition, $legacySteps, $active) {
            $nextNumber = (int) $definition->versions()->max('version') + 1;

            $version = WorkflowVersion::create([
                'workflow_definition_id' => $definition->getKey(),
                'version' => $nextNumber,
                'status' => WorkflowVersion::STATUS_ACTIVE,
                'effective_from' => now()->toDateString(),
                'notes' => 'สร้างจาก approval_steps '.$legacySteps->count().' ขั้น เมื่อ '.now()->toDateTimeString(),
                'published_at' => now(),
            ]);

            $steps = [];

            foreach ($legacySteps->values() as $index => $legacy) {
                $steps[] = WorkflowStep::create([
                    'workflow_version_id' => $version->getKey(),
                    'code' => 'step_'.$legacy->step_order,
                    'name' => $this->stepNameFor($legacy),
                    'step_order' => $legacy->step_order,
                    'type' => WorkflowStep::TYPE_APPROVAL,
                    'assignee_type' => $this->assigneeTypeFor($legacy),
                    'assignee_role_id' => $legacy->role_id,
                    'assignee_user_id' => $legacy->specific_user_id,
                    'completion_policy' => 'any',
                    'is_final' => $index === $legacySteps->count() - 1,
                ]);
            }

            foreach ($steps as $index => $step) {
                $next = $steps[$index + 1] ?? null;

                // อนุมัติ → ไปขั้นถัดไป หรือจบสายเมื่อเป็นขั้นสุดท้าย
                WorkflowTransition::create([
                    'workflow_version_id' => $version->getKey(),
                    'from_step_id' => $step->getKey(),
                    'to_step_id' => $next?->getKey(),
                    'action' => WorkflowTransition::APPROVE,
                    'name' => 'อนุมัติ',
                    'result' => $next === null ? WorkflowInstance::STATUS_COMPLETED : null,
                    'sort_order' => 0,
                ]);

                // ปฏิเสธ → จบสายทันทีทุกขั้น (ตรงกับพฤติกรรมเดิม)
                WorkflowTransition::create([
                    'workflow_version_id' => $version->getKey(),
                    'from_step_id' => $step->getKey(),
                    'to_step_id' => null,
                    'action' => WorkflowTransition::REJECT,
                    'name' => 'ไม่อนุมัติ',
                    'result' => WorkflowInstance::STATUS_REJECTED,
                    'requires_comment' => true,
                    'sort_order' => 1,
                ]);

                /*
                 * ส่งกลับแก้ไข — ของเดิม "ไม่มี" การกระทำนี้
                 *
                 * ประกาศไว้ในสายเพื่อให้ schema/engine รองรับตามที่เฟสนี้กำหนด
                 * แต่ยังไม่มี endpoint ใดเรียกใช้ จึงไม่กระทบพฤติกรรมของระบบ
                 * และ shadow comparison เทียบเฉพาะ approve/reject ที่ของเดิมมี
                 */
                WorkflowTransition::create([
                    'workflow_version_id' => $version->getKey(),
                    'from_step_id' => $step->getKey(),
                    'to_step_id' => $index === 0 ? null : $steps[$index - 1]->getKey(),
                    'action' => WorkflowTransition::RETURN,
                    'name' => 'ส่งกลับแก้ไข',
                    'result' => $index === 0 ? WorkflowInstance::STATUS_RETURNED : null,
                    'requires_comment' => true,
                    'sort_order' => 2,
                ]);

                WorkflowTransition::create([
                    'workflow_version_id' => $version->getKey(),
                    'from_step_id' => $step->getKey(),
                    'to_step_id' => null,
                    'action' => WorkflowTransition::CANCEL,
                    'name' => 'ยกเลิก',
                    'result' => WorkflowInstance::STATUS_CANCELLED,
                    'sort_order' => 3,
                ]);
            }

            // เวอร์ชันเดิมเลิกรับ instance ใหม่ แต่ instance ที่รันอยู่ไม่ถูกแตะ
            if ($active !== null) {
                $active->update([
                    'status' => WorkflowVersion::STATUS_RETIRED,
                    'effective_to' => now()->toDateString(),
                ]);
            }

            return $version;
        }, 5);
    }

    /**
     * สร้าง instance ให้ใบลาหนึ่งใบ โดยสะท้อนสถานะปัจจุบันของมัน
     *
     * **สำคัญ:** ไม่ได้ "เดินสายใหม่" แต่ reconstruct จาก `leave_approvals`
     * ที่เป็นประวัติจริง เพื่อให้ instance สะท้อนความจริง ณ ตอนนี้
     * ประวัติเดิมไม่ถูกแก้ไขแม้แต่แถวเดียว
     */
    public function backfill(Leave $leave): ?WorkflowInstance
    {
        $definition = $this->definition();
        $version = $definition->activeVersion();

        if ($version === null) {
            return null;
        }

        // เรื่องหนึ่งเดินสายเดียวกันได้หลายรอบ (เช่นคำขอยกเลิกที่ยื่นใหม่) — เอารอบล่าสุด
        $existing = WorkflowInstance::query()
            ->where('subject_type', $leave->getMorphClass())
            ->where('subject_id', $leave->getKey())
            ->where('workflow_version_id', $version->getKey())
            ->orderByDesc('run_no')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $approvals = LeaveApproval::where('leave_id', $leave->getKey())
            ->where('stage', $this->approvalStage())
            ->orderBy('step_order')->get();

        if ($approvals->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($leave, $version, $approvals) {
            $steps = $version->steps()->get()->keyBy('step_order');

            $instance = WorkflowInstance::create([
                'workflow_version_id' => $version->getKey(),
                'subject_type' => $leave->getMorphClass(),
                'subject_id' => $leave->getKey(),
                'status' => WorkflowInstance::STATUS_RUNNING,
                'initiated_by' => $leave->user_id,
                'started_at' => $leave->submitted_at ?? $leave->created_at,
                'meta' => ['backfilled_from' => 'leave_approvals', 'stage' => $this->approvalStage(), 'legacy_approval_level' => $this->currentLevel($leave)],
            ]);

            $sequence = 0;
            $currentStepId = null;

            foreach ($approvals as $approval) {
                $step = $steps->get($approval->step_order);

                // ขั้นที่ไม่มีในเวอร์ชันปัจจุบัน = สายอนุมัติเปลี่ยนไปหลังใบลานี้ถูกสร้าง
                if ($step === null) {
                    continue;
                }

                $status = match ($approval->status) {
                    'Approved' => WorkflowTask::STATUS_APPROVED,
                    'Rejected' => WorkflowTask::STATUS_REJECTED,
                    default => WorkflowTask::STATUS_PENDING,
                };

                WorkflowTask::create([
                    'workflow_instance_id' => $instance->getKey(),
                    'workflow_step_id' => $step->getKey(),
                    'status' => $status,
                    'sequence' => $sequence++,
                    'assignee_role_id' => $approval->required_role_id ?? $step->assignee_role_id,
                    'completed_by' => $approval->approver_id,
                    'effective_actor_id' => $approval->approver_id,
                    'delegated_by_id' => $approval->delegated_from_user_id,
                    'completed_at' => $approval->acted_at ?? $approval->approved_at,
                    'comment' => $approval->comment,
                ]);

                if ($status === WorkflowTask::STATUS_PENDING && $currentStepId === null) {
                    $currentStepId = $step->getKey();
                }
            }

            $status = $this->instanceStatusFor($leave, $currentStepId);

            /*
             * ใบลาที่จบแล้วต้องไม่มีขั้นค้าง
             *
             * ใบลาที่ถูกยกเลิกก่อนใครอนุมัติ จะมี `leave_approvals` เป็น Pending
             * ครบทุกแถว ถ้า backfill ตรง ๆ จะได้ instance ที่จบแล้วแต่ยังมี task
             * ค้างอยู่ ทำให้ engine ตอบว่ายังอยู่ขั้นที่ 1 ขณะที่ของเดิมตอบว่าไม่มีขั้น
             * — เป็น mismatch จริงที่ shadow replay จับได้จากใบลาใบที่ 3 ในฐานข้อมูล
             */
            if ($status !== WorkflowInstance::STATUS_RUNNING) {
                $instance->tasks()
                    ->where('status', WorkflowTask::STATUS_PENDING)
                    ->update(['status' => WorkflowTask::STATUS_SKIPPED, 'updated_at' => now()]);

                $currentStepId = null;
            }

            $instance->update([
                'current_step_id' => $currentStepId,
                'status' => $status,
                'completed_at' => $status === WorkflowInstance::STATUS_RUNNING ? null : ($leave->updated_at ?? now()),
            ]);

            return $instance->refresh();
        }, 5);
    }

    /**
     * สถานะของ instance ให้ตรงกับสถานะจริงของใบลา
     */
    protected function instanceStatusFor(Leave $leave, ?int $currentStepId): string
    {
        return match ($this->subjectStatus($leave)) {
            'Pending' => $currentStepId === null ? WorkflowInstance::STATUS_COMPLETED : WorkflowInstance::STATUS_RUNNING,
            'Approved' => WorkflowInstance::STATUS_COMPLETED,
            'Rejected' => WorkflowInstance::STATUS_REJECTED,
            'Cancelled', 'Voided' => WorkflowInstance::STATUS_CANCELLED,
            default => WorkflowInstance::STATUS_RUNNING,
        };
    }

    /** ลายนิ้วมือของสายอนุมัติ ใช้ตัดสินว่าต้องออกเวอร์ชันใหม่หรือไม่ */
    /**
     * แปลง approver_type ของ approval_steps เป็น assignee_type ของ workflow_steps
     *
     * เป็นการแปลงแบบ many-to-one จึงต้องใช้จุดเดียวกันทั้งตอนเขียนเวอร์ชัน
     * และตอนคำนวณ fingerprint มิฉะนั้นสองฝั่งจะพูดคนละคำศัพท์
     */
    protected function assigneeTypeFor(ApprovalStep $legacy): string
    {
        return match ($legacy->approver_type ?? 'ROLE') {
            ApprovalStep::RESOLVER_SPECIFIC_USER => WorkflowStep::ASSIGNEE_USER,
            ApprovalStep::RESOLVER_REQUESTER_SUPERVISOR, ApprovalStep::RESOLVER_DEPARTMENT_HEAD, ApprovalStep::RESOLVER_DIVISION_DIRECTOR, ApprovalStep::RESOLVER_DIRECTOR => WorkflowStep::ASSIGNEE_ORG_MANAGER,
            default => WorkflowStep::ASSIGNEE_ROLE,
        };
    }

    /** ชื่อขั้นตอนที่จะถูกเขียนลง workflow_steps — มี fallback เมื่อ legacy ไม่ได้ตั้งชื่อ */
    protected function stepNameFor(ApprovalStep $legacy): string
    {
        return $legacy->name ?: ('ขั้นตอนที่ '.$legacy->step_order);
    }

    /**
     * fingerprint ของสายอนุมัติปัจจุบัน — ต้องคำนวณด้วย "รูปแบบหลังแปลง"
     * ให้ตรงกับ fingerprintOfVersion() เป๊ะ
     *
     * เดิมฝั่งนี้ใช้ `approver_type` ดิบและ `name` ที่ยังไม่ผ่าน fallback
     * ส่วนอีกฝั่งใช้ `assignee_type` ที่แปลงแล้ว ทำให้เทียบกันไม่มีวันตรง
     * ผลคือ publishCurrentVersion() ออกเวอร์ชันใหม่ทุกครั้งที่ถูกเรียก
     * ทั้งที่สายอนุมัติไม่ได้เปลี่ยน — ผิดข้อกำหนด idempotent
     */
    protected function fingerprintOf($legacySteps): string
    {
        return $legacySteps
            ->map(fn (ApprovalStep $step) => $step->step_order
                .':'.$this->assigneeTypeFor($step)
                .':'.($step->role_id ?? '0')
                .':'.($step->specific_user_id ?? '0')
                .':'.$this->stepNameFor($step))
            ->implode('|');
    }

    protected function fingerprintOfVersion(WorkflowVersion $version): string
    {
        return $version->steps()
            ->get()
            ->map(fn (WorkflowStep $step) => $step->step_order.':'.$step->assignee_type.':'.($step->assignee_role_id ?? '0').':'.($step->assignee_user_id ?? '0').':'.$step->name)
            ->implode('|');
    }
}
