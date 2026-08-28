<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Leave;
use App\Models\LeaveAuditLog;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveGovernanceController extends Controller
{
    public function void(Request $r, Leave $leave, LeaveService $service)
    {
        abort_unless($r->user()->can('leave.management.manage'), 403);
        $data = $r->validate(['reason' => 'required|string|max:1000']);
        DB::transaction(function () use ($r, $leave, $service, $data) {
            $before = $leave->toArray();
            $service->restoreBalance($leave);
            $leave->update(['status' => 'Voided', 'voided_by' => $r->user()->id, 'voided_at' => now(), 'void_reason' => $data['reason']]);
            $leave->delete();
            LeaveAuditLog::create(['leave_id' => $leave->id, 'user_id' => $r->user()->id, 'action' => 'voided', 'before' => $before, 'after' => $leave->toArray(), 'ip_address' => $r->ip(), 'user_agent' => $r->userAgent()]);
        });

        return response()->json(['message' => 'ยกเลิกเอกสารโดยคงประวัติเรียบร้อย']);
    }

    /**
     * ประวัติการดำเนินการของใบลาหนึ่งใบ
     *
     * CLEANUP-02 (D6) — อ่านจาก "ทะเบียนกลาง" ที่เดียว
     *
     * ระบบมีที่บันทึกประวัติของใบลาสามทาง: `leave_audit_logs` (การตัดสินสำคัญ),
     * `leave_action_logs` (การกระทำของผู้ใช้ ซึ่งฝั่งเอกสารใช้เป็นข้อมูลด้วย)
     * และ `audit_logs` ที่เป็นทะเบียนกลาง — สองตารางแรกส่งต่อเข้าทะเบียนกลางให้เอง
     * ผ่าน model hook อยู่แล้ว
     *
     * เดิมหน้าจอนี้อ่านเฉพาะ `leave_audit_logs` ประวัติที่ผู้ใช้เห็นจึง **ขาด**
     * การกระทำที่บันทึกไว้อีกตารางหนึ่ง เช่น การยื่นใบลา การขอยกเลิก และการพิมพ์เอกสาร
     */
    public function audit(Request $r, Leave $leave)
    {
        abort_unless($leave->user_id === $r->user()->id || $r->user()->can('leave.management.manage'), 403);

        return AuditLog::query()
            ->where('resource_type', 'leave')
            ->where('resource_id', (string) $leave->id)
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(100);
    }
}
