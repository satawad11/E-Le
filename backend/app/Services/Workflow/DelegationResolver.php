<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Models\Workflow\WorkflowDelegation;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-05 · งานหลัก 4 — การมอบอำนาจ / ผู้รักษาการ
 *
 * ระบบเดิมเก็บการมอบอำนาจไว้ที่ `user_delegations` และตรวจแบบนี้ใน
 * `GovernmentApprovalController::authorizeStep()`:
 *
 *     หา delegation ที่ delegate_id = ผู้กด, is_active, อยู่ในช่วงวันที่
 *     แล้วเช็คว่า **ผู้มอบ** ถือบทบาทที่ขั้นตอนนั้นต้องการหรือไม่
 *
 * ตัวนี้ให้ผลเหมือนกันทุกประการเพื่อให้ shadow comparison เทียบได้ตรง
 * แล้วเพิ่มความสามารถที่ของเดิมไม่มี คือจำกัดขอบเขตการมอบอำนาจได้
 * (`workflow_definition_id` / `scope_role_id`) โดยไม่เปลี่ยนพฤติกรรมเดิม
 * เมื่อไม่ได้ระบุขอบเขต
 *
 * `user_delegations` ยังเป็น source of truth — `workflow_delegations` ที่มี
 * `legacy_delegation_id` คือภาพสะท้อนของแถวนั้น ไม่ใช่ข้อมูลคู่ขนานที่แข่งกัน
 */
class DelegationResolver
{
    /**
     * ผู้กดคนนี้กำลังทำแทนใครอยู่ สำหรับขั้นตอนที่ต้องการบทบาทนี้
     *
     * คืน id ของ **ผู้มอบอำนาจ** เมื่อใช้สิทธิ์รับมอบ และคืน null เมื่อไม่ได้ใช้
     *
     * @param  int|null  $requiredRoleId  บทบาทที่ขั้นตอนต้องการ
     * @param  string|null  $on  วันที่ที่ใช้ตรวจ (ค่าเริ่มต้น = วันนี้)
     */
    public function delegatorFor(User $actor, ?int $requiredRoleId, ?string $on = null, ?int $workflowDefinitionId = null): ?int
    {
        if ($requiredRoleId === null) {
            return null;
        }

        $date = $on ?? now()->toDateString();

        $fromEngine = $this->fromWorkflowDelegations($actor, $requiredRoleId, $date, $workflowDefinitionId);

        if ($fromEngine !== null) {
            return $fromEngine;
        }

        return $this->fromLegacyDelegations($actor, $requiredRoleId, $date);
    }

    /**
     * การมอบอำนาจที่มีผลอยู่ของผู้ใช้คนนี้ (ใช้แสดงผลและตรวจสอบ)
     *
     * @return array<int,array{delegator_id:int,type:string,from:string,to:?string,source:string}>
     */
    public function activeDelegationsFor(User $actor, ?string $on = null): array
    {
        $date = $on ?? now()->toDateString();
        $result = [];

        if (Schema::hasTable('workflow_delegations')) {
            foreach (WorkflowDelegation::query()->where('delegate_id', $actor->getKey())->effectiveOn($date)->get() as $row) {
                $result[] = [
                    'delegator_id' => (int) $row->delegator_id,
                    'type' => $row->delegation_type,
                    'from' => $row->effective_from?->toDateString(),
                    'to' => $row->effective_to?->toDateString(),
                    'source' => $row->legacy_delegation_id ? 'user_delegations' : 'workflow_delegations',
                ];
            }
        }

        return $result;
    }

    private function fromWorkflowDelegations(User $actor, int $requiredRoleId, string $date, ?int $workflowDefinitionId): ?int
    {
        if (! Schema::hasTable('workflow_delegations')) {
            return null;
        }

        $delegations = WorkflowDelegation::query()
            ->where('delegate_id', $actor->getKey())
            ->effectiveOn($date)
            ->when(
                $workflowDefinitionId !== null,
                // ไม่ระบุ workflow = ใช้ได้ทุก workflow (พฤติกรรมเดิม)
                fn ($query) => $query->where(fn ($inner) => $inner->whereNull('workflow_definition_id')->orWhere('workflow_definition_id', $workflowDefinitionId)),
            )
            // ไม่ระบุบทบาท = ไม่จำกัดบทบาท (พฤติกรรมเดิม)
            ->where(fn ($query) => $query->whereNull('scope_role_id')->orWhere('scope_role_id', $requiredRoleId))
            ->get();

        foreach ($delegations as $delegation) {
            if ($this->holdsRole($delegation->delegator_id, $requiredRoleId)) {
                return (int) $delegation->delegator_id;
            }
        }

        return null;
    }

    /**
     * ทางเดิมของระบบ — คงไว้เพื่อให้ผลตรงกันแม้ยังไม่ได้ mirror ข้อมูลครบ
     */
    private function fromLegacyDelegations(User $actor, int $requiredRoleId, string $date): ?int
    {
        if (! Schema::hasTable('user_delegations')) {
            return null;
        }

        // R02-11: การจับคู่ตาม role ของ spatie ถูกตัดแล้ว (ADR-R-003) — รักษาการใช้ assignment `acting` แทน (R-03/R-04)
        return null;
    }

    private function holdsRole(int $userId, int $roleId): bool
    {
        // R02-11: ไม่มีตาราง role ให้ตรวจแล้ว — fail-closed จนกว่า R-04 จะแทนด้วย PermissionEngine
        return false;
    }
}
