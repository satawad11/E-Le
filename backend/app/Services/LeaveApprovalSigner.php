<?php

namespace App\Services;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\User;
use App\Models\UserDelegation;
use App\Services\Workflow\ApproverResolverService;
use Illuminate\Support\Facades\Storage;

/**
 * ฐานะการลงนามในขั้นอนุมัติ (DELEGATION-01) — ใช้ร่วมกันทั้งการขอลาและคำขอยกเลิก
 *
 *   self     ผู้ยื่นอนุมัติขั้นของตนเอง (เฉพาะขั้นที่เปิด allow_self_approval — LEAVE-APPROVAL-02)
 *   direct   ผู้มีอำนาจของขั้นนั้นลงนามเอง
 *   delegate ผู้ทำหน้าที่แทนตามทะเบียน — พิมพ์ "รักษาราชการแทน/ปฏิบัติราชการแทน …" ใต้ตำแหน่ง
 *   record   ผู้ดูแลบันทึกตามเอกสารกระดาษที่ผู้มีอำนาจลงนามแล้ว — ชื่อผู้ลงนามคือผู้มีอำนาจตัวจริง
 *            ไม่ใช่ผู้ดูแล และไม่มีรูปลายเซ็น (ผู้มีอำนาจไม่ได้ลงนามในระบบ)
 *
 * เดิมผู้ดูแลระบบอนุมัติทุกขั้นในนามตนเอง แบบฟอร์มจึงพิมพ์ชื่อผู้ดูแลเป็นผู้สั่งการ (เจ้าของระบบตัดสิน 2026-10-16:
 * ผู้ดูแลบันทึกแทนได้อย่างเดียว)
 */
class LeaveApprovalSigner
{
    public const MODE_SELF = 'self';

    public const MODE_DIRECT = 'direct';

    public const MODE_DELEGATE = 'delegate';

    public const MODE_RECORD = 'record';

    public const RECORD_COMMENT = 'บันทึกตามเอกสารที่ลงนามแล้ว';

    public function __construct(private readonly ApproverResolverService $resolver) {}

    /**
     * ฐานะที่ actor ใช้ลงนามขั้นนี้ — abort 403/422 ถ้าลงนามไม่ได้
     *
     * @return array{mode: string, signer: User, delegation: ?UserDelegation}
     */
    public function capacity(User $actor, Leave $leave, LeaveApproval|ApprovalStep $step, bool $isApprove, ?int $onBehalfOf = null): array
    {
        $requester = $leave->user;
        abort_if($requester === null, 422, 'ไม่พบผู้ยื่นใบลา');

        if ($actor->id === $leave->user_id) {
            abort_unless($isApprove, 403, 'ห้ามปฏิเสธใบลาหรือคำขอของตนเอง');
            abort_unless($this->resolver->canSelfApprove($requester, $step), 403, 'ขั้นตอนนี้ไม่อนุญาตให้อนุมัติใบลาของตนเอง');

            return ['mode' => self::MODE_SELF, 'signer' => $actor, 'delegation' => null];
        }

        $eligible = $this->resolver->resolveEligibleApproverIds($requester, $step);

        if (in_array($actor->id, $eligible, true)) {
            return ['mode' => self::MODE_DIRECT, 'signer' => $actor, 'delegation' => null];
        }

        if ($delegation = $this->resolver->findDelegation($actor, $requester, $step)) {
            return ['mode' => self::MODE_DELEGATE, 'signer' => $actor, 'delegation' => $delegation];
        }

        abort_unless($this->canRecord($actor), 403, 'ไม่มีสิทธิ์อนุมัติในขั้นตอนนี้');
        abort_if($onBehalfOf === null, 422, 'ท่านไม่ใช่ผู้มีอำนาจลงนามในขั้นนี้ — เลือกผู้มีอำนาจที่ลงนามในเอกสารจริงเพื่อบันทึกแทน');

        $signer = User::with('profile')->find($onBehalfOf);
        abort_if($signer === null || ! $signer->is_active, 422, 'ไม่พบผู้มีอำนาจที่ระบุ');
        abort_if($signer->id === $leave->user_id, 422, 'ผู้ลงนามต้องไม่ใช่ผู้ยื่นใบลา');

        if (in_array($signer->id, $eligible, true)) {
            return ['mode' => self::MODE_RECORD, 'signer' => $signer, 'delegation' => null];
        }

        $delegation = $this->resolver->findDelegation($signer, $requester, $step);
        abort_if($delegation === null, 422, 'ผู้ที่ระบุไม่มีอำนาจลงนามในขั้นนี้ และไม่ได้ทำหน้าที่แทนผู้มีอำนาจ');

        return ['mode' => self::MODE_RECORD, 'signer' => $signer, 'delegation' => $delegation];
    }

    /**
     * ฐานะที่ actor จะใช้ลงนามขั้นนี้ — ใช้แสดงบนหน้ารออนุมัติ (ไม่ abort)
     *
     * @return array{mode: ?string, acting_line: string, record_candidates: array<int, array<string, mixed>>}
     */
    public function describe(User $actor, Leave $leave, LeaveApproval|ApprovalStep $step): array
    {
        $result = ['mode' => null, 'acting_line' => '', 'record_candidates' => []];

        if ($leave->user === null) {
            return $result;
        }

        if ($actor->id === $leave->user_id) {
            return ['mode' => $this->resolver->canSelfApprove($leave->user, $step) ? self::MODE_SELF : null] + $result;
        }

        if (in_array($actor->id, $this->resolver->resolveEligibleApproverIds($leave->user, $step), true)) {
            return ['mode' => self::MODE_DIRECT] + $result;
        }

        if ($delegation = $this->resolver->findDelegation($actor, $leave->user, $step)) {
            return ['mode' => self::MODE_DELEGATE, 'acting_line' => $delegation->actingLine()] + $result;
        }

        if ($this->canRecord($actor)) {
            return ['mode' => self::MODE_RECORD, 'record_candidates' => $this->recordCandidates($leave, $step)] + $result;
        }

        return $result;
    }

    /** ผู้ดูแลที่บันทึกการลงนามแทนได้ */
    public function canRecord(User $actor): bool
    {
        return $actor->can('leave.management.manage')
            || $actor->can('system.manage')
            || $actor->roles->contains(fn ($role) => in_array($role->name, ['Super Admin', 'SuperAdmin'], true));
    }

    /**
     * คอลัมน์ snapshot ของขั้นอนุมัติ ณ เวลาที่ลงนาม
     *
     * @param  array{mode: string, signer: User, delegation: ?UserDelegation}  $capacity
     * @return array<string, mixed>
     */
    public function snapshot(array $capacity, User $actor, ?string $signatureBase64, ?string $comment): array
    {
        $signer = $capacity['signer'];
        $delegation = $capacity['delegation'];
        $record = $capacity['mode'] === self::MODE_RECORD;

        return [
            'approver_id' => $signer->id,
            'approver_name' => $signer->full_name_with_title,
            'approver_position' => $signer->profile?->position,
            'signature_path' => $record ? null : $this->signature($actor, $signatureBase64),
            'comment' => filled($comment) ? $comment : ($record ? self::RECORD_COMMENT : null),
            'delegated_from_user_id' => $delegation?->user_id,
            'acting_type' => $delegation?->delegation_type,
            'acting_position' => $delegation ? ($delegation->acting_position ?: $delegation->user?->profile?->position) : null,
            'acting_order_ref' => $delegation?->orderReference(),
            'recorded_by_user_id' => $record ? $actor->id : null,
            'is_self_approval' => $capacity['mode'] === self::MODE_SELF,
            'approved_at' => now(),
            'acted_at' => now(),
        ];
    }

    /**
     * ลายเซ็นที่ใช้ — ลายเซ็นที่วาดตอนอนุมัติ ถ้าไม่มีใช้ลายเซ็นในโปรไฟล์
     *
     * เดิมการอนุมัติใบลาไม่รับลายเซ็นที่วาด (มีแต่คำขอยกเลิกที่รับ) ทั้งที่หน้าจอให้วาด
     */
    public function signature(User $user, ?string $base64): ?string
    {
        if (! $base64 || ! preg_match('/^data:image\/(\w+);base64,/', $base64, $matches)) {
            return $user->profile?->signature_path;
        }

        $type = strtolower($matches[1]);
        if (! in_array($type, ['jpg', 'jpeg', 'png'], true)) {
            return $user->profile?->signature_path;
        }

        $binary = base64_decode(substr($base64, strpos($base64, ',') + 1), true);
        if ($binary === false || $binary === '' || @getimagesizefromstring($binary) === false) {
            return $user->profile?->signature_path;
        }

        $fileName = 'signatures/'.uniqid('approval_', true).'.'.$type;
        Storage::disk('private_uploads')->put($fileName, $binary);

        return '/storage/'.$fileName;
    }

    /**
     * ผู้ที่ผู้ดูแลเลือกบันทึกแทนได้ — ผู้มีอำนาจของขั้นนี้ และผู้ทำหน้าที่แทนที่มีผลอยู่
     *
     * @return array<int, array{id: int, name: string, position: ?string, acting_line: string}>
     */
    public function recordCandidates(Leave $leave, LeaveApproval|ApprovalStep $step): array
    {
        if ($leave->user === null) {
            return [];
        }

        $eligible = array_values(array_diff($this->resolver->resolveEligibleApproverIds($leave->user, $step), [$leave->user_id]));
        $candidates = User::with('profile')->whereIn('id', $eligible)->where('is_active', true)->get()
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->full_name_with_title, 'position' => $user->profile?->position, 'acting_line' => '']);

        $delegations = UserDelegation::with(['delegate.profile', 'user.profile'])->whereIn('user_id', $eligible)->inEffect()->get();
        foreach ($delegations as $delegation) {
            if ($delegation->delegate && $delegation->delegate->is_active && $delegation->delegate_id !== $leave->user_id) {
                $candidates->push([
                    'id' => $delegation->delegate_id,
                    'name' => $delegation->delegate->full_name_with_title,
                    'position' => $delegation->delegate->profile?->position,
                    'acting_line' => $delegation->actingLine(),
                ]);
            }
        }

        return $candidates->unique('id')->values()->all();
    }
}
