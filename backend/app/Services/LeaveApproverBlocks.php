<?php

namespace App\Services;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\LeaveApproval;
use App\Models\Setting;
use App\Models\UserDelegation;
use App\Support\StoredFile;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * บล็อกผู้ลงนาม (ผู้ตรวจสอบ / ผู้บังคับบัญชา / ผู้สั่งการ) ของแบบฟอร์มใบลา HTML
 *
 * เดิม LeaveDocumentController จับคู่ขั้นอนุมัติกับผู้อนุมัติด้วย `approver->role_id === step->role_id`
 * ซึ่งพลาดทุกขั้นที่ผู้อนุมัติถูกหาด้วย resolver (หัวหน้าแผนก ผู้บังคับบัญชาโดยตรง ผู้ใช้ที่ระบุ ฯลฯ)
 * เพราะผู้อนุมัติไม่จำเป็นต้องมีบทบาทเดียวกับขั้นนั้น — แบบฟอร์มจึงพิมพ์ชื่อว่างทั้งที่อนุมัติแล้ว
 * และใบขอยกเลิกวันลาใช้สายอนุมัติของใบลาแทนสายของคำขอยกเลิก
 *
 * ตอนนี้อ่านจาก snapshot ใน `leave_approvals` ตาม `step_order` ของสายที่ถูกต้อง
 * แบบเดียวกับ Document Engine (LeaveRequestDataProvider)
 */
class LeaveApproverBlocks
{
    /** ชื่อผู้บริหารตามตำแหน่งจากหน้าตั้งค่า — ใช้เมื่อขั้นนั้นยังไม่มีผู้ลงนาม */
    private const DEFAULT_NAME_SETTINGS = [
        'HR Head' => 'head_hr_name',
        'Deputy Director' => 'deputy_admin_name',
        'Director' => 'director_name',
    ];

    private const BLANK_NAME = '........................................................';

    /**
     * @return array<int, array{name: string, date: ?Carbon, signature: ?string, signature_path: ?string, comment: ?string, status: ?string, position: string, role_name: string, acting_line: string, recorded: bool}>
     */
    public function for(Leave $leave, bool $isCancel = false): array
    {
        $steps = ApprovalStep::stepsFor($isCancel ? ApprovalStep::CHAIN_CANCELLATION : ApprovalStep::CHAIN_LEAVE)->load('role');
        $approvals = $this->approvals($leave, $isCancel);
        $settings = Setting::pluck('value', 'key');

        $blocks = $steps->map(function (ApprovalStep $step) use ($approvals, $settings) {
            $roleName = (string) ($step->role?->name ?? '');
            $approval = $approvals->get($step->step_order);
            $acted = $approval !== null && in_array($approval->status, ['Approved', 'Rejected'], true);
            $settingKey = self::DEFAULT_NAME_SETTINGS[$roleName] ?? null;

            return [
                'name' => $approval
                    ? ($approval->approver_name ?: ($approval->approver?->full_name_with_title ?: (string) $approval->approver?->name))
                    : (string) ($settingKey !== null && filled($settings[$settingKey] ?? null) ? $settings[$settingKey] : self::BLANK_NAME),
                // วันที่/ลายเซ็น/ความเห็นแสดงเฉพาะขั้นที่ลงนามแล้ว — ขั้นที่ยังรออยู่ต้องเป็นช่องว่าง
                'date' => $acted ? Carbon::parse($approval->acted_at ?? $approval->approved_at ?? $approval->updated_at) : null,
                'signature' => $acted && $approval->signature_path ? StoredFile::path($approval->signature_path) : null,
                // path ตามที่เก็บ (ไม่ใช่ path บนดิสก์) — Document Engine อ่านรูปผ่าน disk ของระบบเอง
                'signature_path' => $acted ? $approval->signature_path : null,
                'comment' => $acted ? $approval->comment : null,
                'status' => $approval?->status,
                'position' => (string) ($approval?->approver_position ?: ($step->role?->description ?? $step->name)),
                'role_name' => $roleName,
                // "ปฏิบัติราชการแทน …" เมื่อผู้ทำหน้าที่แทนลงนาม (DELEGATION-01) — จาก snapshot ณ วันลงนาม
                'acting_line' => $acted ? UserDelegation::lineFor($approval->acting_type, $approval->acting_position) : '',
                // ผู้ดูแลบันทึกตามเอกสารที่ลงนามแล้ว — ไม่มีรูปลายเซ็นในระบบ
                'recorded' => $acted && $approval->recorded_by_user_id !== null,
            ];
        })->values()->all();

        if ($isCancel && $approvals->isEmpty() && $blocks !== []) {
            $blocks[array_key_last($blocks)] = $this->legacyCancellationDecision($leave) ?? $blocks[array_key_last($blocks)];
        }

        return $blocks;
    }

    /**
     * คำขอยกเลิกที่ตัดสินก่อนมีสายอนุมัติของคำขอยกเลิก (ไม่มีแถว stage=cancellation)
     * มีแต่บันทึก LeaveActionLog ของผู้สั่งการ — ใส่ไว้ที่บล็อกสุดท้าย (คำสั่ง) ตามที่เคยพิมพ์
     *
     * @return array<string, mixed>|null
     */
    private function legacyCancellationDecision(Leave $leave): ?array
    {
        $log = LeaveActionLog::with('user.profile')
            ->where('leave_id', $leave->id)
            ->whereIn('action', ['Cancellation Approved', 'Cancellation Rejected'])
            ->orderByDesc('id')
            ->first();

        if ($log === null || $log->user === null) {
            return null;
        }

        return [
            'name' => $log->user->full_name_with_title ?: (string) $log->user->name,
            'date' => Carbon::parse($log->created_at),
            'signature' => $log->user->profile?->signature_path ? StoredFile::path($log->user->profile->signature_path) : null,
            'signature_path' => $log->user->profile?->signature_path,
            'comment' => $log->details['comment'] ?? null,
            'status' => $log->action === 'Cancellation Approved' ? 'Approved' : 'Rejected',
            'position' => (string) ($log->user->profile?->position ?? ''),
            'role_name' => 'Director',
            'acting_line' => '',
            'recorded' => false,
        ];
    }

    /**
     * @return Collection<int, LeaveApproval> คีย์เป็น step_order
     */
    private function approvals(Leave $leave, bool $isCancel): Collection
    {
        $relation = $isCancel ? 'cancellationApprovals' : 'approvals';
        $leave->loadMissing($relation.'.approver');

        return $leave->{$relation}
            ->filter(fn (LeaveApproval $approval) => $approval->step_order !== null)
            ->sortBy('id')
            ->keyBy('step_order');
    }
}
