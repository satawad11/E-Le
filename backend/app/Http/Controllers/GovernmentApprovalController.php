<?php

namespace App\Http\Controllers;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveAuditLog;
use App\Models\UserDelegation;
use App\Notifications\LeaveStatusChanged;
use App\Services\LeaveService;
use App\Services\LineNotificationService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GovernmentApprovalController extends Controller
{
    public function __construct(private LeaveService $service, private TelegramService $telegram, private LineNotificationService $line) {}

    /**
     * แจ้งเตือนผู้ยื่นใบลาทุกช่องทาง — ห้ามให้ช่องทางภายนอกล้มแล้วทำให้คำขออนุมัติพังตาม
     */
    private function notify(Leave $leave, string $status, string $actorName): void
    {
        $leave->user->notify(new LeaveStatusChanged($leave, $status, $actorName));
        $type = $leave->type->name ?? 'การลา';
        $message = $status === 'Approved'
         ? "✅ ใบลาของท่านได้รับการอนุมัติเรียบร้อยแล้ว\nประเภท: {$type}\nตั้งแต่วันที่: ".$leave->start_date?->format('d/m/Y')."\nถึงวันที่: ".$leave->end_date?->format('d/m/Y')
         : "❌ ใบลาของท่านถูกปฏิเสธ\nประเภท: {$type}\nเหตุผล: ".($leave->approvals()->orderByDesc('acted_at')->value('comment') ?: 'ไม่มี');
        try {
            if ($leave->user->telegram_chat_id) {
                $this->telegram->sendMessage($leave->user->telegram_chat_id, $message);
            }
            $this->line->sendMessage($message, $leave->user->line_user_id ?? null);
        } catch (\Throwable $e) {
            Log::warning('ส่งแจ้งเตือนใบลาไม่สำเร็จ', ['leave_id' => $leave->id, 'error' => $e->getMessage()]);
        }
    }

    private function step(Leave $leave): LeaveApproval
    {
        $snapshot = $leave->approvals()->where('step_order', $leave->approval_level)->first();
        if ($snapshot) {
            return $snapshot;
        }$legacy = ApprovalStep::where('step_order', $leave->approval_level)->first();
        abort_unless($legacy, 422, ApprovalStep::exists() ? 'ไม่พบขั้นตอนอนุมัติลำดับที่ '.$leave->approval_level.' กรุณาตรวจสอบการตั้งค่าลำดับการอนุมัติ' : 'ยังไม่ได้ตั้งค่าลำดับการอนุมัติในระบบ กรุณาตั้งค่าที่เมนูจัดการลำดับการอนุมัติก่อน');

        return LeaveApproval::firstOrCreate(['leave_id' => $leave->id, 'step_order' => $legacy->step_order], ['step_name' => $legacy->name, 'required_role_id' => $legacy->role_id, 'status' => 'Pending']);
    }

    private function authorizeStep(Request $r, LeaveApproval $step): ?int
    {
        // LEGACY-04 (04-4): เลิกเช็คชื่อ role — Super Admin ยังผ่านทาง Gate::before
        if ($r->user()->can('leave.management.manage')) {
            return null;
        }if ($r->user()->roles->contains('id', $step->required_role_id)) {
            return null;
        }$delegation = UserDelegation::where('delegate_id', $r->user()->id)->where('is_active', true)->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->whereHas('user.roles', fn ($q) => $q->where('roles.id', $step->required_role_id))->first();
        abort_unless($delegation, 403, 'ไม่มีสิทธิ์อนุมัติในขั้นตอนนี้');

        return $delegation->user_id;
    }

    public function approve(Request $r, Leave $leave)
    {
        $data = $r->validate(['comment' => 'nullable|string|max:1000']);
        abort_unless($leave->status === 'Pending', 422, 'ใบลาไม่ได้อยู่ระหว่างอนุมัติ');
        abort_if($leave->user_id === $r->user()->id, 403, 'ห้ามอนุมัติใบลาของตนเอง');
        if ($leave->substitute_id) {
            abort_unless($leave->substitute_status === 'Accepted', 422, 'ผู้ปฏิบัติงานแทนยังไม่ตอบรับ');
        }DB::transaction(function () use ($r, $leave, $data) {
            $locked = Leave::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $step = $this->step($locked);
            $delegatedFrom = $this->authorizeStep($r, $step);
            $step->update(['approver_id' => $r->user()->id, 'approver_name' => $r->user()->full_name_with_title, 'approver_position' => $r->user()->profile?->position, 'signature_path' => $r->user()->profile?->signature_path, 'status' => 'Approved', 'comment' => $data['comment'] ?? null, 'approved_at' => now(), 'acted_at' => now(), 'delegated_from_user_id' => $delegatedFrom]);
            $next = $locked->approvals()->where('step_order', '>', $step->step_order)->orderBy('step_order')->first();
            if ($next) {
                $locked->update(['approval_level' => $next->step_order]);
            } else {
                abort_unless($this->service->deductBalance($locked), 422, 'วันลาคงเหลือไม่เพียงพอ');
                $locked->update(['status' => 'Approved']);
            }LeaveAuditLog::create(['leave_id' => $locked->id, 'user_id' => $r->user()->id, 'action' => 'approval_approved', 'after' => ['step' => $step->step_order, 'final' => ! $next], 'ip_address' => $r->ip(), 'user_agent' => $r->userAgent()]);
        }, 5);
        $leave->refresh();
        if ($leave->status === 'Approved') {
            $this->notify($leave, 'Approved', $r->user()->name);
        }

        return response()->json(['message' => 'อนุมัติเรียบร้อย', 'status' => $leave->status]);
    }

    public function reject(Request $r, Leave $leave)
    {
        $data = $r->validate(['comment' => 'required|string|max:1000']);
        abort_unless($leave->status === 'Pending', 422, 'ใบลาไม่ได้อยู่ระหว่างอนุมัติ');
        abort_if($leave->user_id === $r->user()->id, 403, 'ห้ามปฏิเสธใบลาของตนเอง');
        DB::transaction(function () use ($r, $leave, $data) {
            $locked = Leave::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $step = $this->step($locked);
            $delegatedFrom = $this->authorizeStep($r, $step);
            $step->update(['approver_id' => $r->user()->id, 'approver_name' => $r->user()->full_name_with_title, 'approver_position' => $r->user()->profile?->position, 'signature_path' => $r->user()->profile?->signature_path, 'status' => 'Rejected', 'comment' => $data['comment'], 'approved_at' => now(), 'acted_at' => now(), 'delegated_from_user_id' => $delegatedFrom]);
            $this->service->restoreBalance($locked);
            $locked->update(['status' => 'Rejected']);
            LeaveAuditLog::create(['leave_id' => $locked->id, 'user_id' => $r->user()->id, 'action' => 'approval_rejected', 'after' => ['step' => $step->step_order, 'comment' => $data['comment']], 'ip_address' => $r->ip(), 'user_agent' => $r->userAgent()]);
        }, 5);
        $leave->refresh();
        $this->notify($leave, 'Rejected', $r->user()->name);

        return response()->json(['message' => 'ปฏิเสธใบลาเรียบร้อย']);
    }
}
