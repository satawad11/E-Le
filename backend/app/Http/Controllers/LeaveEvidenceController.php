<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\LeaveAuditLog;
use App\Models\LeaveEvidence;
use App\Support\UploadRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LeaveEvidenceController extends Controller
{
    /**
     * ใครเข้าถึงหลักฐานประกอบการลาได้บ้าง
     *
     * LEGACY-07 · 07-4 (LEAVE-004) — เดิมอนุญาตเฉพาะเจ้าของใบลา ผู้ถือ
     * `leave.management.manage` และ `leave.approval.hr` เท่านั้น
     * **ผู้อนุมัติในสายงานปัจจุบันเข้าถึงใบรับรองแพทย์ไม่ได้** ทั้งที่ต้องใช้
     * ประกอบการพิจารณา จึงต้องขอจากผู้ลาผ่านช่องทางนอกระบบ
     *
     * เพิ่มผู้อนุมัติเข้ามา โดยยืนบนสองด่านที่มีอยู่แล้ว ไม่สร้างกฎใหม่:
     *   - `LeavePolicy::view()` — ผู้ที่เห็นใบลาใบนี้ได้ตามขอบเขตหน่วยงาน (ABAC)
     *   - ต้องเป็นผู้อนุมัติจริง ๆ คือถือสิทธิ์อนุมัติในสาย
     *
     * ผลคือ **ไม่ได้ขยายขอบเขตหน่วยงาน** — ผู้อนุมัติที่เห็นใบลาข้ามหน่วยงานไม่ได้
     * ก็ยังเข้าถึงหลักฐานของใบนั้นไม่ได้เช่นกัน
     */
    private function authorizeAccess(Request $r, Leave $leave): void
    {
        $user = $r->user();

        if ($leave->user_id === $user->id
            || $user->can('leave.management.manage')
            || $user->can('leave.approval.hr')) {
            return;
        }

        $isApprover = $user->can('leave.approval.dept') || $user->can('leave.approval.final');

        abort_unless($isApprover && $user->can('view', $leave), 403);
    }

    public function index(Request $r, Leave $leave)
    {
        $this->authorizeAccess($r, $leave);

        return $leave->evidences()->with(['uploader:id,name', 'verifier:id,name'])->get();
    }

    public function store(Request $r, Leave $leave)
    {
        $this->authorizeAccess($r, $leave);
        // SEC-023R — allowlist กลางที่ `config/uploads.php` (ชนิดไฟล์จริง + นามสกุล)
        $data = $r->validate(['files' => 'required|array|max:10', 'files.*' => UploadRules::attachment(), 'evidence_type' => 'required|string|max:80', 'issued_date' => 'nullable|date']);
        $created = [];
        foreach ($r->file('files') as $file) {
            $path = Storage::disk('local')->putFile('private/leave-evidences', $file);
            $created[] = LeaveEvidence::create(['leave_id' => $leave->id, 'uploaded_by' => $r->user()->id, 'evidence_type' => $data['evidence_type'], 'original_name' => $file->getClientOriginalName(), 'storage_path' => $path, 'mime_type' => $file->getMimeType(), 'file_size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'issued_date' => $data['issued_date'] ?? null]);
        }LeaveAuditLog::create(['leave_id' => $leave->id, 'user_id' => $r->user()->id, 'action' => 'evidence_uploaded', 'after' => ['ids' => collect($created)->pluck('id')], 'ip_address' => $r->ip(), 'user_agent' => $r->userAgent()]);

        return response()->json($created, 201);
    }

    public function download(Request $r, LeaveEvidence $evidence)
    {
        $this->authorizeAccess($r, $evidence->leave);
        abort_unless(Storage::disk('local')->exists($evidence->storage_path), 404);

        return Storage::disk('local')->download($evidence->storage_path, $evidence->original_name, ['Content-Type' => $evidence->mime_type, 'Cache-Control' => 'private, no-store']);
    }

    public function verify(Request $r, LeaveEvidence $evidence)
    {
        abort_unless($r->user()->can('leave.management.manage') || $r->user()->can('leave.approval.hr'), 403);
        $data = $r->validate(['status' => 'required|in:verified,rejected', 'note' => 'nullable|string|max:1000']);
        $evidence->update(['verification_status' => $data['status'], 'verification_note' => $data['note'] ?? null, 'verified_by' => $r->user()->id, 'verified_at' => now()]);

        return $evidence->fresh('verifier:id,name');
    }
}
