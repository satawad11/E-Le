<?php

namespace App\Http\Controllers;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\LeaveBalance;
use App\Models\Setting;
use App\Services\DocumentEngine\LeaveDocumentGateway;
use App\Services\Export\LeaveWordService;
use App\Services\LeaveDocumentTemplateResolver;
use App\Services\LeaveVerificationService;
use App\Services\ThaiDateService;
use App\Support\StoredFile;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\PhpWord;

class LeaveDocumentController extends Controller
{
    /**
     * แบบฟอร์มที่ต้องใช้พิมพ์ใบลาใบนี้
     *
     * เดิมตรงนี้เทียบชื่อไทยของประเภทการลาแบบตายตัว (`'ลาพักผ่อน'`, `'ลาอุปสมบท'` ฯลฯ)
     * ผู้ดูแลจึงเพิ่ม/เปลี่ยนชื่อประเภทการลาไม่ได้โดยไม่ทำให้พิมพ์ผิดแบบฟอร์ม
     * ตอนนี้ยึด `leave_types.document_template_code` ที่ตั้งค่าได้จากหน้าผู้ดูแล
     */
    /** ชื่อไฟล์เอกสารที่ส่งให้ผู้ใช้ — ใช้ร่วมกันทั้งเส้นทางเดิมและ Document Engine */
    private function filenameFor(Leave $leave, bool $isCancel, string $extension = 'pdf'): string
    {
        $leaveTypeName = $leave->type->name ?? 'การลา';
        $userName = str_replace(' ', '_', trim((string) $leave->user?->name));
        $docNumber = $leave->document_number ?? $leave->id;

        $base = str_replace(['/', '\\'], '_', "{$leaveTypeName}_{$userName}_{$docNumber}");

        return ($isCancel ? "cancel_{$base}" : $base).'.'.$extension;
    }

    private function viewFor(Leave $leave, bool $isCancel): string
    {
        if ($isCancel) {
            return 'pdf.leave_cancel';
        }

        $templates = app(LeaveDocumentTemplateResolver::class);

        return $templates->viewForCode($templates->templateCodeForLeave($leave)) ?? 'pdf.leave';
    }

    /**
     * แบบฟอร์มใบลาพิมพ์ได้ต่อเมื่อใบลานั้นเคยได้รับอนุมัติจริง
     *
     * เดิมดาวน์โหลดได้ทุกสถานะ ใบที่ยังรออนุมัติหรือถูกปฏิเสธจึงพิมพ์ออกมาได้
     * เป็นกระดาษหน้าตาเหมือนใบลาที่ผ่านการอนุมัติแล้ว ทั้งที่ยังไม่มีใครอนุมัติ
     *
     * ใบที่อนุมัติแล้วภายหลังถูกยกเลิกหรือเพิกถอนยังพิมพ์ได้ตามเดิม เพราะเอกสารนั้น
     * ออกไปจริงและอยู่ในทะเบียนคุมการลา · ส่วนแบบฟอร์ม "ใบยกเลิก" มีเงื่อนไขของตัวเอง
     * คือต้องเคยยื่นคำขอยกเลิกมาก่อน
     */
    private function assertIssued(Leave $leave, bool $isCancellationForm): void
    {
        if ($isCancellationForm) {
            /*
             * ใบที่ผู้ยื่นถอนเองตั้งแต่ยังรออนุมัติ ไม่มีแบบฟอร์มใบยกเลิก เพราะไม่เคยมี
             * เอกสารให้ยกเลิก · เงื่อนไข `status === 'Cancelled'` คงไว้เพื่อรองรับข้อมูลเก่า
             * ที่ยกเลิกไปก่อนระบบบันทึก `cancellation_status` — แต่ต้องเป็นใบที่ออกเอกสารแล้วจริง
             */
            abort_unless(
                $leave->hasCancellationRequest() || ($leave->status === 'Cancelled' && $leave->wasIssued()),
                422,
                'ใบลานี้ยังไม่มีคำขอยกเลิก จึงยังไม่มีแบบฟอร์มใบยกเลิกให้พิมพ์',
            );

            return;
        }

        abort_unless(
            $leave->wasIssued(),
            422,
            'ใบลานี้ยังไม่ได้รับอนุมัติ จึงยังไม่มีเอกสารให้ดาวน์โหลด — จะพิมพ์ได้เมื่อผ่านการอนุมัติแล้ว',
        );
    }

    /**
     * Export a leave as PDF.
     */
    public function exportPdf(Request $request, Leave $leave, ThaiDateService $thaiDateService)
    {
        // SEC-003 — เอกสารมีเหตุผลการลา ที่อยู่ติดต่อ และลายเซ็นของผู้ลาอยู่ในนั้น
        $this->authorize('view', $leave);
        $this->assertIssued($leave, $request->query('form') === 'cancel');

        $leave->load(['user.department', 'user.roles', 'user.profile', 'type', 'approvals.approver']);

        /*
         * DOC-ENGINE — ถ้าใบลาประเภทนี้ถูกเปิดให้ใช้ Document Engine แล้ว
         * ให้ engine ออกเอกสารจากแบบฟอร์ม PDF ต้นฉบับ
         *
         * gateway คืน null เมื่อธงปิด ยังไม่ได้ผูกแบบฟอร์ม หรือเรนเดอร์ไม่สำเร็จ
         * ซึ่งแปลว่า "ใช้เส้นทางเดิม" — ผู้ใช้จึงพิมพ์เอกสารได้เสมอไม่ว่าจะเกิดอะไรขึ้น
         */
        $engineContent = app(LeaveDocumentGateway::class)->pdfFor(
            $leave,
            $request->query('form') === 'cancel',
            $request->user(),
        );

        if ($engineContent !== null) {
            return response($engineContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$this->filenameFor($leave, $request->query('form') === 'cancel').'"',
            ]);
        }

        $lastLeave = Leave::where('user_id', $leave->user_id)
            ->where('leave_type_id', $leave->leave_type_id)
            ->where('status', 'Approved')
            ->where('id', '<', $leave->id)
            ->orderBy('id', 'desc')
            ->first();

        $settings = Setting::pluck('value', 'key');

        $approvalSteps = ApprovalStep::stepsFor(ApprovalStep::CHAIN_LEAVE)->load('role');
        $roleSettingMap = [
            'HR Head' => 'head_hr_name',
            'Deputy Director' => 'deputy_admin_name',
            'Director' => 'director_name',
        ];
        $isCancel = $request->query('form') === 'cancel';
        $cancelLog = null;
        if ($isCancel) {
            $cancelLog = LeaveActionLog::with('user.profile')
                ->where('leave_id', $leave->id)
                ->whereIn('action', ['Cancellation Approved', 'Cancellation Rejected'])
                ->orderBy('id', 'desc')
                ->first();
        }

        $approverBlocks = [];
        foreach ($approvalSteps as $step) {
            $roleName = $step->role ? $step->role->name : '';

            if ($isCancel && $roleName === 'Director' && $cancelLog) {
                $status = $cancelLog->action === 'Cancellation Approved' ? 'Approved' : 'Rejected';
                $approverBlocks[] = [
                    'name' => $cancelLog->user->full_name_with_title ?? $cancelLog->user->name,
                    'date' => Carbon::parse($cancelLog->created_at),
                    'signature' => $cancelLog->user->profile && $cancelLog->user->profile->signature_path ? StoredFile::path($cancelLog->user->profile->signature_path) : null,
                    'comment' => $cancelLog->details['comment'] ?? null,
                    'status' => $status,
                    'position' => $cancelLog->user->profile->position ?? ($step->role->description ?? $step->name),
                    'role_name' => $roleName,
                ];

                continue;
            }

            $approval = $leave->approvals->filter(fn ($a) => $a->approver && $a->approver->role_id === $step->role_id)->first();
            $settingKey = $roleSettingMap[$roleName] ?? '';
            $defaultName = $settingKey && isset($settings[$settingKey]) ? $settings[$settingKey] : '........................................................';

            $positionTitle = $step->role ? ($step->role->description ?? $step->name) : $step->name;

            $approverBlocks[] = [
                'name' => $approval ? ($approval->approver_name ?: $approval->approver->name) : $defaultName,
                'date' => $approval ? Carbon::parse($approval->updated_at) : null,
                'signature' => $approval && $approval->signature_path ? StoredFile::path($approval->signature_path) : null,
                'comment' => $approval ? $approval->comment : null,
                'status' => $approval ? $approval->status : null,
                'position' => $positionTitle,
                'role_name' => $roleName,
            ];
        }

        $viewName = $this->viewFor($leave, $isCancel);

        $leaveBalance = null;
        if ($viewName === 'pdf.leave_vacation') {
            $year = Carbon::parse($leave->start_date)->year;
            $leaveBalance = LeaveBalance::where('user_id', $leave->user_id)
                ->where('leave_type_id', $leave->leave_type_id)
                ->where('year', $year)
                ->first();
        }

        // Calculate 2 Fiscal Semesters Stats for Standard Leave Form
        $statSem1 = ['ป่วย' => ['used_times' => '', 'used_days' => ''], 'กิจ' => ['used_times' => '', 'used_days' => ''], 'คลอด' => ['used_times' => '', 'used_days' => '']];
        $statSem2 = ['ป่วย' => ['used_times' => '', 'used_days' => ''], 'กิจ' => ['used_times' => '', 'used_days' => ''], 'คลอด' => ['used_times' => '', 'used_days' => '']];

        // QR ตรวจสอบเอกสาร ฝังเป็น data URI เพราะ dompdf โหลดรูปจาก URL ภายนอกไม่ได้
        $verification = app(LeaveVerificationService::class);
        $verificationHash = $verification->ensureHash($leave);
        $verificationQr = $verification->qrCodeDataUri($verificationHash);
        $verificationUrl = $verification->verificationUrl($verificationHash);

        /*
         * LEGACY-07 · 07-1 — เรนเดอร์จากเวอร์ชันเทมเพลตที่ใบลานี้ pin ไว้
         *
         * ใบลาที่อนุมัติไปแล้วต้องพิมพ์ออกมาเหมือนเดิมเสมอ แม้ผู้ดูแลจะแก้เทมเพลต
         * ภายหลัง — เป็นข้อกำหนดของ ACCEPTANCE-CRITERIA และ `docs/14`
         */
        $templates = app(LeaveDocumentTemplateResolver::class);
        $paper = $templates->paperFor($leave, $viewName) ?? ['paper' => 'a4', 'orientation' => 'portrait'];

        $html = $templates->render($leave, $viewName, compact(
            'leave', 'thaiDateService', 'lastLeave', 'leaveBalance', 'settings',
            'approverBlocks', 'statSem1', 'statSem2',
            'verificationQr', 'verificationUrl', 'verificationHash'
        ));

        $pdf = Pdf::loadHTML($html)->setPaper($paper['paper'], $paper['orientation']);

        $leaveTypeName = $leave->type->name ?? 'การลา';
        $userName = str_replace(' ', '_', trim($leave->user->name));
        $docNumber = $leave->document_number ?? $leave->id;

        $baseFilename = "{$leaveTypeName}_{$userName}_{$docNumber}";
        $baseFilename = str_replace(['/', '\\'], '_', $baseFilename);

        $filename = $isCancel ? "cancel_{$baseFilename}.pdf" : "{$baseFilename}.pdf";

        return $pdf->download($filename);
    }

    /**
     * Export a leave as Word.
     */
    public function exportWord(Request $request, Leave $leave, ThaiDateService $thaiDateService)
    {
        // SEC-003 — เช่นเดียวกับ exportPdf
        $this->authorize('view', $leave);
        $this->assertIssued($leave, $request->query('form') === 'cancel');

        $leave->load(['user.department', 'user.roles', 'user.profile', 'type', 'approvals.approver']);

        $lastLeave = Leave::where('user_id', $leave->user_id)
            ->where('leave_type_id', $leave->leave_type_id)
            ->where('status', 'Approved')
            ->where('id', '<', $leave->id)
            ->orderBy('id', 'desc')
            ->first();

        $settings = Setting::pluck('value', 'key');

        $approvalSteps = ApprovalStep::stepsFor(ApprovalStep::CHAIN_LEAVE)->load('role');
        $roleSettingMap = [
            'HR Head' => 'head_hr_name',
            'Deputy Director' => 'deputy_admin_name',
            'Director' => 'director_name',
        ];
        $isCancel = $request->query('form') === 'cancel';
        $cancelLog = null;
        if ($isCancel) {
            $cancelLog = LeaveActionLog::with('user.profile')
                ->where('leave_id', $leave->id)
                ->whereIn('action', ['Cancellation Approved', 'Cancellation Rejected'])
                ->orderBy('id', 'desc')
                ->first();
        }

        $approverBlocks = [];
        foreach ($approvalSteps as $step) {
            $roleName = $step->role ? $step->role->name : '';

            if ($isCancel && $roleName === 'Director' && $cancelLog) {
                $status = $cancelLog->action === 'Cancellation Approved' ? 'Approved' : 'Rejected';
                $approverBlocks[] = [
                    'name' => $cancelLog->user->full_name_with_title ?? $cancelLog->user->name,
                    'date' => Carbon::parse($cancelLog->created_at),
                    'signature' => $cancelLog->user->profile && $cancelLog->user->profile->signature_path ? StoredFile::path($cancelLog->user->profile->signature_path) : null,
                    'comment' => $cancelLog->details['comment'] ?? null,
                    'status' => $status,
                    'position' => $cancelLog->user->profile->position ?? ($step->role->description ?? $step->name),
                    'role_name' => $roleName,
                ];

                continue;
            }

            $approval = $leave->approvals->filter(fn ($a) => $a->approver && $a->approver->role_id === $step->role_id)->first();
            $settingKey = $roleSettingMap[$roleName] ?? '';
            $defaultName = $settingKey && isset($settings[$settingKey]) ? $settings[$settingKey] : '........................................................';

            $positionTitle = $step->role ? ($step->role->description ?? $step->name) : $step->name;

            $approverBlocks[] = [
                'name' => $approval ? ($approval->approver_name ?: $approval->approver->name) : $defaultName,
                'date' => $approval ? Carbon::parse($approval->updated_at) : null,
                'signature' => $approval && $approval->signature_path ? StoredFile::path($approval->signature_path) : null,
                'comment' => $approval ? $approval->comment : null,
                'status' => $approval ? $approval->status : null,
                'position' => $positionTitle,
                'role_name' => $roleName,
            ];
        }

        $viewName = $this->viewFor($leave, $isCancel);

        $leaveBalance = null;
        if ($viewName === 'pdf.leave_vacation') {
            $year = Carbon::parse($leave->start_date)->year;
            $leaveBalance = LeaveBalance::where('user_id', $leave->user_id)
                ->where('leave_type_id', $leave->leave_type_id)
                ->where('year', $year)
                ->first();
        }

        $year = Carbon::parse($leave->start_date)->year;
        $balancesQuery = LeaveBalance::with('type')
            ->where('user_id', $leave->user_id)
            ->where('year', $year)
            ->get();

        $statBalances = [];
        foreach ($balancesQuery as $b) {
            if (! $b->type) {
                continue;
            }
            $typeName = $b->type->name;
            $used = $b->used;
            if ($leave->status === 'Approved' && ($leave->type->name ?? '') === $typeName) {
                $usedBefore = max(0, $used - $leave->total_days);
                $total = $used;
            } else {
                $usedBefore = $used;
                $total = $used + (($leave->type->name ?? '') === $typeName ? $leave->total_days : 0);
            }
            $statBalances[$typeName] = [
                'used_before' => $usedBefore > 0 ? $usedBefore : '',
                'total' => $total > 0 ? $total : '',
            ];
        }

        $leaveTypeName = $leave->type->name ?? 'การลา';
        $userName = str_replace(' ', '_', trim($leave->user->name));
        $docNumber = $leave->document_number ?? $leave->id;

        $baseFilename = "{$leaveTypeName}_{$userName}_{$docNumber}";
        $baseFilename = str_replace(['/', '\\'], '_', $baseFilename);

        if (class_exists(PhpWord::class)) {
            $wordService = app(LeaveWordService::class);
            $tempFile = $wordService->generateLeaveDocx($leave, $settings->toArray(), $approverBlocks);
            $filename = $isCancel ? "cancel_{$baseFilename}.docx" : "{$baseFilename}.docx";

            return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
        }

        $statSem1 = ['ป่วย' => ['used_times' => '', 'used_days' => ''], 'กิจ' => ['used_times' => '', 'used_days' => ''], 'คลอด' => ['used_times' => '', 'used_days' => '']];
        $statSem2 = ['ป่วย' => ['used_times' => '', 'used_days' => ''], 'กิจ' => ['used_times' => '', 'used_days' => ''], 'คลอด' => ['used_times' => '', 'used_days' => '']];

        // LEGACY-07 · 07-1 — เรนเดอร์จากเวอร์ชันเทมเพลตที่ใบลานี้ pin ไว้
        $html = app(LeaveDocumentTemplateResolver::class)->render(
            $leave,
            $viewName,
            compact('leave', 'thaiDateService', 'lastLeave', 'leaveBalance', 'settings', 'approverBlocks', 'statSem1', 'statSem2'),
        );
        $content = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"></head><body>'.$html.'</body></html>';
        $filename = $isCancel ? "cancel_{$baseFilename}.doc" : "{$baseFilename}.doc";

        return response($content)
            ->header('Content-Type', 'application/vnd.ms-word')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }
}
