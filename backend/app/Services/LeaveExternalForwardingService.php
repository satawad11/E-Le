<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\LeaveAuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * การลาที่ต้องส่งต่อผู้มีอำนาจเหนือสถานศึกษา (REDESIGN-2569 เฟส 3.4 · D-01 เจ้าของระบบ 18/10/2569)
 *
 * เจ้าของระบบกำหนด: "ให้ผู้อำนวยการอนุมัติก่อน แล้วค่อยนำส่งต่อ" — ใบลาจึงเดินสายอนุมัติของวิทยาลัยจนผู้อำนวยการ
 * อนุมัติเหมือนเดิม แล้วระบบติดสถานะ "รอส่งต่อ" ให้งานบุคลากรบันทึกการส่งหนังสือและผลจากผู้มีอำนาจภายนอก
 * ระบบไม่ได้เชื่อมกับหน่วยงานภายนอก จึงเป็นการบันทึก ไม่ใช่การอนุมัติแทน
 *
 * ต้องส่งต่อเมื่อ (นโยบายการลาที่ใบลาผูกอยู่ · leave_policies.eligibility_rules):
 *   "requires_external_approval": true       การลาประเภทนี้เกินอำนาจของสถานศึกษา
 *   "external_approval_after_days": 60       วันลาประเภทนี้ในปีเดียวกัน (รวมใบนี้) เกินจำนวนที่กำหนด
 * และทุกใบลาของผู้อำนวยการเอง (หัวหน้าหน่วยงานสูงสุด) — ผู้อำนวยการอนุมัติใบลาของตนเองเป็นที่สุดไม่ได้
 *
 * สถานะเก็บใน leaves.meta_data.external_forwarding (ไม่เปลี่ยน schema): awaiting_dispatch → awaiting_result → approved | rejected
 * ผลไม่อนุญาต = ยกเลิกเอกสารและคืนวันลาตามขั้นตอนเดิม (Voided) ประวัติทุกขั้นอยู่ใน leave_audit_logs
 */
class LeaveExternalForwardingService
{
    public const AWAITING_DISPATCH = 'awaiting_dispatch';

    public const AWAITING_RESULT = 'awaiting_result';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const LABELS = [
        self::AWAITING_DISPATCH => 'รอส่งต่อผู้มีอำนาจ',
        self::AWAITING_RESULT => 'ส่งต่อแล้ว รอผล',
        self::APPROVED => 'ผู้มีอำนาจอนุญาตแล้ว',
        self::REJECTED => 'ผู้มีอำนาจไม่อนุญาต',
    ];

    public function __construct(private LeaveService $leaves, private LeavePolicyService $policies) {}

    /** เหตุที่ต้องส่งต่อ หรือ null ถ้าจบในวิทยาลัยได้ */
    public function reasonFor(Leave $leave): ?string
    {
        if ($this->isDirector($leave->user_id, Carbon::parse($leave->start_date))) {
            return 'ใบลาของผู้อำนวยการ ต้องได้รับอนุญาตจากผู้มีอำนาจเหนือสถานศึกษา';
        }

        $rules = $leave->policy?->eligibility_rules ?? [];
        if (! empty($rules['requires_external_approval'])) {
            return 'การลาประเภทนี้เกินอำนาจของสถานศึกษา ต้องได้รับอนุญาตจากผู้มีอำนาจเหนือสถานศึกษา';
        }

        $limit = (float) ($rules['external_approval_after_days'] ?? 0);
        if ($limit > 0 && $leave->policy) {
            $year = $this->policies->periodYearForLeave($leave);
            $total = Leave::where('user_id', $leave->user_id)
                ->where('leave_type_id', $leave->leave_type_id)
                ->where('status', 'Approved')
                ->get()
                ->filter(fn (Leave $l) => $this->policies->periodYearForLeave($l) === $year)
                ->sum('total_days');
            if ($total > $limit) {
                $days = rtrim(rtrim(number_format($limit, 1, '.', ''), '0'), '.');

                return "รวมวันลาประเภทนี้ในปีเกิน {$days} วัน ต้องได้รับอนุญาตจากผู้มีอำนาจเหนือสถานศึกษา";
            }
        }

        return null;
    }

    /** เรียกหลังผู้อำนวยการอนุมัติขั้นสุดท้าย (หรืองานบุคลากรบันทึกการลาแทนที่อนุมัติแล้ว) */
    public function flagIfRequired(Leave $leave, ?User $actor = null): void
    {
        $reason = $this->reasonFor($leave->fresh(['policy']) ?? $leave);
        if ($reason === null) {
            return;
        }

        $this->put($leave, ['status' => self::AWAITING_DISPATCH, 'reason' => $reason, 'flagged_at' => now()->toIso8601String()]);
        $this->log($leave, $actor, 'external_forwarding_required', ['reason' => $reason]);
    }

    public function recordDispatch(Leave $leave, User $actor, array $data): Leave
    {
        return DB::transaction(function () use ($leave, $actor, $data) {
            $leave = Leave::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($leave, self::AWAITING_DISPATCH, 'ใบลานี้ไม่ได้อยู่ในสถานะรอส่งต่อ');

            $this->put($leave, [
                'status' => self::AWAITING_RESULT,
                'authority' => $data['authority'],
                'dispatch_number' => $data['dispatch_number'],
                'dispatch_date' => $data['dispatch_date'],
                'dispatched_by' => $actor->id,
            ]);
            $this->log($leave, $actor, 'external_forwarding_dispatched', $data);

            return $leave;
        });
    }

    public function recordResult(Leave $leave, User $actor, array $data): Leave
    {
        return DB::transaction(function () use ($leave, $actor, $data) {
            $leave = Leave::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($leave, self::AWAITING_RESULT, 'ใบลานี้ยังไม่ได้บันทึกการส่งต่อ หรือบันทึกผลไปแล้ว');

            $approved = $data['result'] === self::APPROVED;
            $this->put($leave, [
                'status' => $approved ? self::APPROVED : self::REJECTED,
                'result_number' => $data['result_number'],
                'result_date' => $data['result_date'],
                'result_note' => $data['note'] ?? null,
                'result_recorded_by' => $actor->id,
            ]);
            $this->log($leave, $actor, $approved ? 'external_forwarding_approved' : 'external_forwarding_rejected', $data);

            if (! $approved) {
                // ผู้มีอำนาจไม่อนุญาต = ไม่มีการลานี้ — ยกเลิกเอกสารและคืนวันลาด้วยขั้นตอนเดียวกับการยกเลิกเอกสาร
                $before = $leave->toArray();
                $this->leaves->restoreBalance($leave);
                $leave->update([
                    'status' => 'Voided', 'voided_by' => $actor->id, 'voided_at' => now(),
                    'void_reason' => 'ผู้มีอำนาจไม่อนุญาต ตามหนังสือที่ '.$data['result_number'],
                ]);
                $leave->delete();
                LeaveAuditLog::create(['leave_id' => $leave->id, 'user_id' => $actor->id, 'action' => 'voided', 'before' => $before, 'after' => $leave->toArray()]);
            }

            return $leave;
        });
    }

    public static function statusOf(Leave $leave): ?string
    {
        return $leave->meta_data['external_forwarding']['status'] ?? null;
    }

    private function isDirector(int $userId, Carbon $on): bool
    {
        $rootId = DB::table('org_units')->whereNull('parent_id')->value('id');

        return $rootId && DB::table('org_unit_managers')
            ->where('org_unit_id', $rootId)
            ->where('user_id', $userId)
            ->whereDate('effective_from', '<=', $on->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on->toDateString()))
            ->exists();
    }

    private function assertStatus(Leave $leave, string $expected, string $message): void
    {
        if ($leave->status !== 'Approved' || self::statusOf($leave) !== $expected) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function put(Leave $leave, array $values): void
    {
        $meta = $leave->meta_data ?? [];
        $meta['external_forwarding'] = array_merge($meta['external_forwarding'] ?? [], $values);
        $leave->forceFill(['meta_data' => $meta])->save();
    }

    private function log(Leave $leave, ?User $actor, string $action, array $after): void
    {
        LeaveAuditLog::create([
            'leave_id' => $leave->id, 'user_id' => $actor?->id, 'action' => $action, 'after' => $after,
            'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent(),
        ]);
    }
}
