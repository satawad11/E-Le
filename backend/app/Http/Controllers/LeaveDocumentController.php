<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\Setting;
use App\Services\DocumentEngine\LeaveDocumentGateway;
use App\Services\Export\LeaveWordService;
use App\Services\LeaveApproverBlocks;
use App\Services\LeaveDocumentTemplateResolver;
use App\Services\LeaveVerificationService;
use App\Services\ThaiDateService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\PhpWord;

class LeaveDocumentController extends Controller
{
    /** ชื่อไฟล์เอกสารที่ส่งให้ผู้ใช้ — ใช้ร่วมกันทั้งเส้นทางเดิมและ Document Engine */
    private function filenameFor(Leave $leave, bool $isCancel, string $extension = 'pdf'): string
    {
        $leaveTypeName = $leave->type->name ?? 'การลา';
        $userName = str_replace(' ', '_', trim((string) $leave->user?->name));
        $docNumber = $leave->document_number ?? $leave->id;

        $base = str_replace(['/', '\\'], '_', "{$leaveTypeName}_{$userName}_{$docNumber}");

        return ($isCancel ? "cancel_{$base}" : $base).'.'.$extension;
    }

    /**
     * แบบฟอร์มที่ต้องใช้พิมพ์ใบลาใบนี้
     *
     * ยึด `leave_types.document_template_code` ที่ตั้งค่าได้จากหน้าผู้ดูแล
     * (เดิมเทียบชื่อไทยของประเภทการลาแบบตายตัว)
     */
    private function viewFor(Leave $leave, bool $isCancel): string
    {
        if ($isCancel) {
            return 'pdf.leave_cancel';
        }

        $templates = app(LeaveDocumentTemplateResolver::class);

        return $templates->viewForCode($templates->templateCodeForLeave($leave)) ?? 'pdf.leave';
    }

    /**
     * ตัวแปรของแบบฟอร์ม HTML — ใช้ร่วมกันระหว่าง PDF และ Word
     *
     * ค่ามาตรฐานของแบบฟอร์ม (ชื่อ ตำแหน่ง วันที่ สถิติ ยอดวันลาพักผ่อน ฯลฯ) มาจาก
     * `LeaveDocumentFields` ผ่าน resolver ที่นี่เหลือเฉพาะของที่แบบฟอร์มรุ่นเก่ายังอ่านตรง
     *
     * @return array<string, mixed>
     */
    private function formData(Leave $leave, bool $isCancel, ThaiDateService $thaiDateService): array
    {
        $lastLeave = Leave::where('user_id', $leave->user_id)
            ->where('leave_type_id', $leave->leave_type_id)
            ->where('status', 'Approved')
            ->where('id', '<', $leave->id)
            ->orderBy('id', 'desc')
            ->first();

        return [
            'leave' => $leave,
            'thaiDateService' => $thaiDateService,
            'lastLeave' => $lastLeave,
            'settings' => Setting::pluck('value', 'key'),
            'approverBlocks' => app(LeaveApproverBlocks::class)->for($leave, $isCancel),
        ];
    }

    /**
     * Export a leave as PDF.
     */
    public function exportPdf(Request $request, Leave $leave, ThaiDateService $thaiDateService)
    {
        // SEC-003 — เอกสารมีเหตุผลการลา ที่อยู่ติดต่อ และลายเซ็นของผู้ลาอยู่ในนั้น
        $this->authorize('view', $leave);

        $leave->load(['user.personnel', 'type', 'approvals.approver']);
        $isCancel = $request->query('form') === 'cancel';

        /*
         * DOC-ENGINE — ถ้าใบลาประเภทนี้ถูกเปิดให้ใช้ Document Engine แล้ว
         * ให้ engine ออกเอกสารจากแบบฟอร์ม PDF ต้นฉบับ
         *
         * gateway คืน null เมื่อธงปิด ยังไม่ได้ผูกแบบฟอร์ม หรือเรนเดอร์ไม่สำเร็จ
         * ซึ่งแปลว่า "ใช้เส้นทางเดิม" — ผู้ใช้จึงพิมพ์เอกสารได้เสมอไม่ว่าจะเกิดอะไรขึ้น
         */
        $engineContent = app(LeaveDocumentGateway::class)->pdfFor($leave, $isCancel, $request->user());

        if ($engineContent !== null) {
            return response($engineContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$this->filenameFor($leave, $isCancel).'"',
            ]);
        }

        $viewName = $this->viewFor($leave, $isCancel);

        // QR ตรวจสอบเอกสาร ฝังเป็น data URI เพราะ dompdf โหลดรูปจาก URL ภายนอกไม่ได้
        $verification = app(LeaveVerificationService::class);
        $verificationHash = $verification->ensureHash($leave);

        /*
         * LEGACY-07 · 07-1 — เวอร์ชันแบบฟอร์มที่ใบลานี้ pin ไว้กำหนดขนาดกระดาษ
         * เนื้อหาแบบฟอร์มมาจากไฟล์ blade ในโค้ดเสมอ (TPL-SEC-01)
         */
        $templates = app(LeaveDocumentTemplateResolver::class);
        $paper = $templates->paperFor($leave, $viewName) ?? ['paper' => 'a4', 'orientation' => 'portrait'];

        $html = $templates->render($leave, $viewName, array_merge($this->formData($leave, $isCancel, $thaiDateService), [
            'verificationHash' => $verificationHash,
            'verificationQr' => $verification->qrCodeDataUri($verificationHash),
            'verificationUrl' => $verification->verificationUrl($verificationHash),
        ]));

        return Pdf::loadHTML($html)
            ->setPaper($paper['paper'], $paper['orientation'])
            ->download($this->filenameFor($leave, $isCancel));
    }

    /**
     * Export a leave as Word.
     */
    public function exportWord(Request $request, Leave $leave, ThaiDateService $thaiDateService)
    {
        // SEC-003 — เช่นเดียวกับ exportPdf
        $this->authorize('view', $leave);

        $leave->load(['user.personnel', 'type', 'approvals.approver']);
        $isCancel = $request->query('form') === 'cancel';
        $data = $this->formData($leave, $isCancel, $thaiDateService);

        if (class_exists(PhpWord::class)) {
            $tempFile = app(LeaveWordService::class)->generateLeaveDocx($leave, $data['settings']->toArray(), $data['approverBlocks']);

            return response()->download($tempFile, $this->filenameFor($leave, $isCancel, 'docx'))->deleteFileAfterSend(true);
        }

        // LEGACY-07 · 07-1 — แบบฟอร์มเดียวกับ PDF
        $html = app(LeaveDocumentTemplateResolver::class)->render($leave, $this->viewFor($leave, $isCancel), $data);
        $content = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"></head><body>'.$html.'</body></html>';

        return response($content)
            ->header('Content-Type', 'application/vnd.ms-word')
            ->header('Content-Disposition', 'attachment; filename="'.$this->filenameFor($leave, $isCancel, 'doc').'"');
    }
}
