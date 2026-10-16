<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDelegation;
use App\Services\AuditLogger;
use App\Services\LeaveApprovalSigner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ทะเบียนการรักษาราชการแทน / ปฏิบัติราชการแทน (DELEGATION-01)
 *
 * งานบุคลากรบันทึกตามคำสั่งแต่งตั้ง และรับรองคำขอที่ผู้ใช้ส่งจากหน้าโปรไฟล์
 * ไม่มีการลบ — ยกเลิกด้วยสถานะ revoked เพราะใบลาที่ลงนามแล้วอ้างอิงแถวนี้อยู่
 */
class DelegationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LeaveApprovalSigner $signer,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeRegister($request);
        $request->validate(['status' => ['nullable', Rule::in([UserDelegation::STATUS_PENDING, UserDelegation::STATUS_APPROVED, UserDelegation::STATUS_REVOKED])]]);

        $person = fn (?User $u) => $u ? ['id' => $u->id, 'name' => $u->full_name_with_title, 'position' => $u->profile?->position] : null;

        // ส่งเฉพาะชื่อ/ตำแหน่ง — ไม่ส่งโมเดล User ทั้งก้อน (ข้อมูลส่วนบุคคลใน profile)
        $delegations = UserDelegation::with(['user.profile', 'delegate.profile', 'requester.profile', 'approver.profile', 'revoker.profile'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
            ->orderByDesc('start_date')
            ->limit(500)
            ->get()
            ->map(fn (UserDelegation $d) => [
                'id' => $d->id,
                'user_id' => $d->user_id,
                'delegate_id' => $d->delegate_id,
                'delegation_type' => $d->delegation_type,
                'acting_position' => $d->acting_position,
                'order_number' => $d->order_number,
                'order_date' => $d->order_date?->toDateString(),
                'start_date' => $d->start_date?->toDateString(),
                'end_date' => $d->end_date?->toDateString(),
                'note' => $d->note,
                'status' => $d->status,
                'is_active' => $d->is_active,
                'approved_at' => $d->approved_at,
                'revoked_at' => $d->revoked_at,
                'user' => $person($d->user),
                'delegate' => $person($d->delegate),
                'requester' => $person($d->requester),
                'approver' => $person($d->approver),
                'revoker' => $person($d->revoker),
                'acting_line' => $d->actingLine(),
                'in_effect' => $this->inEffect($d),
            ]);

        return response()->json([
            'data' => $delegations,
            'types' => collect(UserDelegation::TYPE_LABELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeRegister($request);
        $data = $this->validated($request);

        $delegation = UserDelegation::create($data + [
            'is_active' => true,
            'status' => UserDelegation::STATUS_APPROVED,
            'requested_by' => $request->user()->id,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        $this->audit->log('delegation_created', $delegation, after: $delegation->toArray());

        return response()->json(['message' => 'บันทึกการทำหน้าที่แทนเรียบร้อยแล้ว', 'delegation' => $delegation], 201);
    }

    public function update(Request $request, UserDelegation $delegation)
    {
        $this->authorizeRegister($request);
        abort_if($delegation->status === UserDelegation::STATUS_REVOKED, 422, 'รายการที่ยกเลิกแล้วแก้ไขไม่ได้ — บันทึกรายการใหม่แทน');

        $data = $this->validated($request);
        $before = $delegation->toArray();
        $delegation->update($data);

        $this->audit->log('delegation_updated', $delegation, before: $before, after: $delegation->fresh()->toArray());

        return response()->json(['message' => 'แก้ไขการทำหน้าที่แทนเรียบร้อยแล้ว', 'delegation' => $delegation->fresh()]);
    }

    /** รับรองคำขอที่ผู้ใช้ส่งมา — งานบุคลากรเติมเลขที่คำสั่งและตำแหน่งที่ทำหน้าที่แทนได้ในขั้นนี้ */
    public function approve(Request $request, UserDelegation $delegation)
    {
        $this->authorizeRegister($request);
        abort_unless($delegation->status === UserDelegation::STATUS_PENDING, 422, 'รายการนี้ไม่ได้รอการรับรอง');

        $data = $request->validate([
            'delegation_type' => ['nullable', Rule::in(UserDelegation::TYPES)],
            'acting_position' => 'nullable|string|max:255',
            'order_number' => 'nullable|string|max:100',
            'order_date' => 'nullable|date',
        ]);

        $before = $delegation->toArray();
        $delegation->update(array_filter($data, fn ($v) => $v !== null) + [
            'status' => UserDelegation::STATUS_APPROVED,
            'is_active' => true,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        $this->audit->log('delegation_approved', $delegation, before: $before, after: $delegation->fresh()->toArray());

        return response()->json(['message' => 'รับรองการทำหน้าที่แทนเรียบร้อยแล้ว', 'delegation' => $delegation->fresh()]);
    }

    public function revoke(Request $request, UserDelegation $delegation)
    {
        $this->authorizeRegister($request);
        abort_if($delegation->status === UserDelegation::STATUS_REVOKED, 422, 'รายการนี้ถูกยกเลิกไปแล้ว');
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $before = $delegation->toArray();
        DB::transaction(fn () => $delegation->update([
            'status' => UserDelegation::STATUS_REVOKED,
            'is_active' => false,
            'revoked_by' => $request->user()->id,
            'revoked_at' => now(),
        ]));

        $this->audit->log('delegation_revoked', $delegation, before: $before, after: $delegation->fresh()->toArray(), reason: $data['reason'] ?? null);

        return response()->json(['message' => 'ยกเลิกการทำหน้าที่แทนเรียบร้อยแล้ว', 'delegation' => $delegation->fresh()]);
    }

    /** งานบุคลากร/ผู้ดูแลวันลา — ชุดเดียวกับผู้ที่บันทึกการลงนามแทนผู้มีอำนาจได้ (ตรวจด้วย can() แบบงานจัดการวันลาอื่น) */
    private function authorizeRegister(Request $request): void
    {
        abort_unless($request->user() && $this->signer->canRecord($request->user()), 403, 'ไม่มีสิทธิ์จัดการทะเบียนการปฏิบัติหน้าที่แทน');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'delegate_id' => 'required|integer|exists:users,id|different:user_id',
            'delegation_type' => ['required', Rule::in(UserDelegation::TYPES)],
            'acting_position' => 'nullable|string|max:255',
            'order_number' => 'nullable|string|max:100',
            'order_date' => 'nullable|date',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'note' => 'nullable|string|max:500',
        ], [
            'delegate_id.different' => 'ผู้ทำหน้าที่แทนต้องไม่ใช่บุคคลเดียวกับผู้มอบ',
        ]);

        abort_unless(User::whereKey($data['delegate_id'])->where('is_active', true)->exists(), 422, 'ผู้ทำหน้าที่แทนต้องเป็นบัญชีที่ใช้งานอยู่');

        return $data;
    }

    private function inEffect(UserDelegation $d): bool
    {
        $today = now()->startOfDay();

        return $d->is_active && $d->status === UserDelegation::STATUS_APPROVED
            && $d->start_date && $d->end_date
            && $d->start_date->lte($today) && $d->end_date->gte($today);
    }
}
