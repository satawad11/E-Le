<?php

namespace App\Services\Workflow;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveApproval;

/**
 * สายอนุมัติ "คำขอยกเลิกใบลา" บน Workflow Engine
 *
 * CLEANUP-02 (D4) — เอกสารปิดเฟส LEGACY-05 ระบุว่าสายนี้ยังใช้ `approval_level` +
 * `ApprovalStep` ตรง ๆ และ **ยังไม่ถูกครอบด้วย shadow** จึงเป็น "สายที่สองที่ต้องรวมเข้า
 * engine ก่อน cutover" — ตอนนี้เดินบนโครงเดียวกับสายขอลาแล้ว
 *
 * ต่างจากสายขอลาเพียงสามอย่าง และทั้งสามอยู่ในเมธอดที่ override ไว้ด้านล่าง:
 *   1. ชุดขั้นตอนต้นทางคือ `approval_steps` สาย `CHAIN_CANCELLATION`
 *   2. แถวสรุปที่อ่านคือ `leave_approvals` stage `CANCELLATION`
 *   3. ขั้นปัจจุบันอยู่ที่ `leaves.cancellation_approval_level` ไม่ใช่ `approval_level`
 *      (ห้ามใช้คอลัมน์เดียวกัน เพราะ `approval_level` เป็นประวัติว่าใบลาเดิม
 *      ผ่านอนุมัติมาถึงขั้นไหน)
 *
 * ใบลาหนึ่งใบจึงมีได้สอง instance คนละ definition — ทุกจุดที่ค้นหา instance
 * ต้องระบุ definition เสมอ มิฉะนั้นจะหยิบสายผิด
 */
class LeaveCancellationWorkflowProvisioner extends LeaveWorkflowProvisioner
{
    public const DEFINITION_CODE = 'leave.cancellation';

    protected function definitionCode(): string
    {
        return self::DEFINITION_CODE;
    }

    protected function definitionName(): string
    {
        return 'สายการอนุมัติคำขอยกเลิกใบลา';
    }

    protected function definitionDescription(): string
    {
        return 'แปลงจาก approval_steps สายยกเลิกที่ใช้งานอยู่เดิม (CLEANUP-02 · D4)';
    }

    protected function legacyChain(): string
    {
        return ApprovalStep::CHAIN_CANCELLATION;
    }

    protected function approvalStage(): string
    {
        return LeaveApproval::STAGE_CANCELLATION;
    }

    protected function currentLevel(Leave $leave): ?int
    {
        return $leave->cancellation_approval_level ?: $leave->approval_level;
    }

    /**
     * สถานะของ "คำขอยกเลิก" ไม่ใช่ของใบลา
     *
     * ใบลาที่อนุมัติแล้วและมีคำขอยกเลิกค้างอยู่ ต้องได้ instance ที่ยังรันอยู่
     * ถ้าดู `leaves.status` จะเห็นเป็น Approved แล้วปิด instance ทิ้งทันที
     */
    protected function subjectStatus(Leave $leave): ?string
    {
        return match ($leave->cancellation_status) {
            'Pending' => 'Pending',
            'Approved' => 'Approved',
            'Rejected' => 'Rejected',
            default => null,
        };
    }
}
