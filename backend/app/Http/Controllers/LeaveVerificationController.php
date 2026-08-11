<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Services\LeaveVerificationService;
use Illuminate\Http\Request;

/**
 * ตรวจสอบใบลาที่พิมพ์ออกไปผ่าน QR — เปิดสาธารณะเช่นเดียวกับการตรวจสอบหนังสือของสารบรรณ
 *
 * เข้าถึงได้เฉพาะผู้ที่ถือเอกสาร (โทเคน 40 ตัวอักษรเดาไม่ได้) และคืนเฉพาะข้อมูล
 * ที่ปรากฏบนหน้าเอกสารอยู่แล้ว ไม่เปิดเผยเหตุผลการลาหรือข้อมูลส่วนบุคคลอื่น
 */
class LeaveVerificationController extends Controller
{
    public function __construct(private LeaveVerificationService $verification) {}

    public function verify(string $hash)
    {
        $leave = Leave::withTrashed()
            ->with(['user.profile:id,user_id,title,first_name,last_name,position', 'type:id,name', 'approvals'])
            ->where('verification_hash', $hash)
            ->first();

        abort_unless($leave, 404, 'ไม่พบเอกสารที่ตรงกับรหัสตรวจสอบนี้');

        $statusLabels = [
            'Pending' => 'อยู่ระหว่างพิจารณา', 'Approved' => 'อนุมัติแล้ว',
            'Rejected' => 'ไม่อนุมัติ', 'Cancelled' => 'ยกเลิกแล้ว', 'Voided' => 'ยกเลิกเอกสาร',
        ];

        $profile = $leave->user?->profile;
        $tampered = $leave->status === 'Approved'
            && $leave->content_hash !== null
            && $leave->content_hash !== $this->verification->contentHash($leave);

        return response()->json([
            'document_number' => $leave->document_number,
            'holder' => [
                'name' => trim(($profile?->title ?? '') . ($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? '')) ?: $leave->user?->name,
                'position' => $profile?->position,
            ],
            'leave_type' => $leave->type?->name,
            'start_date' => $leave->start_date?->toDateString(),
            'end_date' => $leave->end_date?->toDateString(),
            'total_days' => (float) $leave->total_days,
            'status' => $leave->status,
            'status_label' => $leave->trashed() ? 'ยกเลิกเอกสาร' : ($statusLabels[$leave->status] ?? $leave->status),
            'approved_at' => $leave->approvals->where('status', 'Approved')->max('approved_at'),
            'approvers' => $leave->approvals->where('status', 'Approved')
                ->map(fn ($a) => ['name' => $a->approver_name, 'position' => $a->approver_position])->values(),
            // เอกสารถือว่าใช้อ้างอิงได้เมื่ออนุมัติแล้ว ยังไม่ถูกยกเลิก และเนื้อหาไม่ถูกแก้ไขภายหลัง
            'is_valid' => $leave->status === 'Approved' && !$leave->trashed() && !$tampered,
            'content_altered' => $tampered,
            'verified_at' => now()->toIso8601String(),
        ]);
    }

    public function qrcode(string $hash)
    {
        abort_unless(Leave::withTrashed()->where('verification_hash', $hash)->exists(), 404);

        return response($this->verification->qrCodeSvg($hash))
            ->header('Content-Type', 'image/svg+xml');
    }
}
