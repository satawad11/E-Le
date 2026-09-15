<?php

namespace App\Http\Controllers;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\LeaveBalance;
use App\Models\Setting;
use App\Models\User;
use App\Services\DocumentEngine\LeaveDocumentGateway;
use App\Services\LeaveDocumentTemplateResolver;
use App\Services\LeaveVerificationService;
use App\Services\ThaiDateService;
use App\Services\Workflow\ApproverResolverService;
use App\Support\DocumentFilename;
use App\Support\StoredFile;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LeaveDocumentController extends Controller
{
    /**
     * ช่องข้อความในหน้าตั้งค่าที่ใช้เป็น **ค่าสำรอง** ของชื่อผู้ลงนามแต่ละบทบาท
     *
     * ใช้ต่อเมื่อระบบชี้ตัวผู้มีอำนาจจริงไม่ได้เท่านั้น — ดู `pendingApproverName()`
     */
    private const ROLE_NAME_SETTINGS = [
        'HR Head' => 'head_hr_name',
        'Deputy Director' => 'deputy_admin_name',
        'Director' => 'director_name',
    ];

    /** เส้นประในช่องลงนามที่ยังไม่มีชื่อ — ให้เขียนด้วยมือได้ */
    private const UNSIGNED_NAME_PLACEHOLDER = '........................................................';

    /**
     * แบบฟอร์มที่ต้องใช้พิมพ์ใบลาใบนี้
     *
     * เดิมตรงนี้เทียบชื่อไทยของประเภทการลาแบบตายตัว (`'ลาพักผ่อน'`, `'ลาอุปสมบท'` ฯลฯ)
     * ผู้ดูแลจึงเพิ่ม/เปลี่ยนชื่อประเภทการลาไม่ได้โดยไม่ทำให้พิมพ์ผิดแบบฟอร์ม
     * ตอนนี้ยึด `leave_types.document_template_code` ที่ตั้งค่าได้จากหน้าผู้ดูแล
     */
    /**
     * ชื่อไฟล์เอกสารที่ส่งให้ผู้ใช้ — ใช้ร่วมกันทั้งเส้นทางเดิมและ Document Engine
     *
     * ขึ้นต้นด้วยชนิดเอกสารเป็นภาษาไทย ("ใบลา" / "ใบยกเลิกวันลา") ผู้ใช้ที่ดาวน์โหลด
     * ทั้งสองแบบของใบเดียวกันจึงแยกไฟล์ออกจากกันได้ทันทีในโฟลเดอร์ดาวน์โหลด
     * เดิมใบยกเลิกใช้คำนำหน้า `cancel_` ซึ่งเป็นภาษาอังกฤษปนอยู่ในชื่อไทยทั้งไฟล์
     */
    private function filenameFor(Leave $leave, bool $isCancel, string $extension = 'pdf'): string
    {
        return DocumentFilename::make([
            $isCancel ? 'ใบยกเลิกวันลา' : 'ใบลา',
            $leave->type->name ?? 'การลา',
            $leave->user?->name,
            $leave->document_number ?? $leave->id,
        ], $extension);
    }

    /**
     * ช่องลงนามของแต่ละขั้นในสายอนุมัติ — ใช้ร่วมกันทั้งเส้นทาง PDF และ Word
     *
     * เดิมบล็อกนี้ถูกคัดลอกไว้ทั้งใน `exportPdf()` และ `exportWord()` แบบตัวต่อตัว
     * เอกสารสองแบบของใบเดียวกันจึงเสี่ยงเพี้ยนจากกันทุกครั้งที่แก้ที่เดียว
     *
     * @param  Collection<string,mixed>  $settings
     * @return array<int,array<string,mixed>>
     */
    private function approverBlocksFor(Leave $leave, bool $isCancel, $settings): array
    {
        $approvalSteps = ApprovalStep::stepsFor(ApprovalStep::CHAIN_LEAVE)->load('role');

        $cancelLog = null;
        if ($isCancel) {
            $cancelLog = LeaveActionLog::with('user.profile')
                ->where('leave_id', $leave->id)
                ->whereIn('action', ['Cancellation Approved', 'Cancellation Rejected'])
                ->orderBy('id', 'desc')
                ->first();
        }

        $blocks = [];
        foreach ($approvalSteps as $step) {
            $roleName = $step->role ? $step->role->name : '';

            if ($isCancel && $roleName === 'Director' && $cancelLog) {
                $status = $cancelLog->action === 'Cancellation Approved' ? 'Approved' : 'Rejected';
                $blocks[] = [
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
            $positionTitle = $step->role ? ($step->role->description ?? $step->name) : $step->name;

            $blocks[] = [
                'name' => $approval
                    ? ($approval->approver_name ?: $approval->approver->name)
                    : $this->pendingApproverName($leave, $step, $roleName, $settings),
                'date' => $approval ? Carbon::parse($approval->updated_at) : null,
                'signature' => $approval && $approval->signature_path ? StoredFile::path($approval->signature_path) : null,
                'comment' => $approval ? $approval->comment : null,
                'status' => $approval ? $approval->status : null,
                'position' => $positionTitle,
                'role_name' => $roleName,
            ];
        }

        return $blocks;
    }

    /**
     * ชื่อที่พิมพ์ในช่องลงนามที่ยังไม่มีใครลงนาม
     *
     * **ที่มา:** ชื่อนี้เคยมาจากช่องข้อความที่ผู้ดูแลพิมพ์เองในหน้าตั้งค่า
     * (`head_hr_name`, `deputy_admin_name`, `director_name`) ซึ่งเป็นข้อมูลคนละชุด
     * กับทะเบียนผู้มีอำนาจจริงของระบบ · เมื่อมีการย้ายหรือเปลี่ยนผู้รับผิดชอบ
     * แต่ไม่มีใครกลับไปแก้ช่องข้อความ ใบลาเปล่าที่พิมพ์ออกไปให้เสนอเซ็น
     * ก็จะขึ้นชื่อ **คนที่ไม่ใช่ผู้มีอำนาจลงนามแล้ว** บนเอกสารราชการ
     *
     * ตอนนี้ถามระบบก่อนว่าขั้นนี้ใครเป็นผู้มีอำนาจจริงสำหรับผู้ยื่นรายนี้ — ใช้ตัวแก้
     * ตัวเดียวกับที่สายอนุมัติใช้จริง จึงตรงกับคนที่จะได้รับเรื่องแน่นอน
     * ใช้ชื่อจากระบบเฉพาะเมื่อผู้มีอำนาจมี **คนเดียว** เท่านั้น เพราะถ้ามีหลายคน
     * การเดาพิมพ์ไปคนหนึ่งผิดยิ่งกว่าการเว้นว่าง · กรณีอื่นตกไปใช้ค่าที่ตั้งไว้ในหน้าตั้งค่า
     * แล้วจึงเป็นเส้นประให้เขียนด้วยมือ
     *
     * @param  Collection<string,mixed>  $settings
     */
    private function pendingApproverName(Leave $leave, ApprovalStep $step, string $roleName, $settings): string
    {
        $resolved = $this->soleEligibleApproverName($leave, $step);

        if ($resolved !== null) {
            return $resolved;
        }

        $settingKey = self::ROLE_NAME_SETTINGS[$roleName] ?? '';
        $fallback = $settingKey ? trim((string) ($settings[$settingKey] ?? '')) : '';

        return $fallback !== '' ? $fallback : self::UNSIGNED_NAME_PLACEHOLDER;
    }

    /**
     * ชื่อผู้มีอำนาจอนุมัติของขั้นนี้ เมื่อมีอยู่คนเดียวเท่านั้น
     *
     * การหาผู้มีอำนาจแตะทั้งผังหน่วยงาน ทะเบียนผู้รับผิดชอบ และการมอบอำนาจ
     * ถ้าข้อมูลชุดใดไม่พร้อม การพิมพ์เอกสารต้องไม่ล้มตามไปด้วย — หลักเดียวกับ
     * Document Engine ที่คืน null แล้วให้ใช้เส้นทางเดิม
     */
    private function soleEligibleApproverName(Leave $leave, ApprovalStep $step): ?string
    {
        if ($leave->user === null) {
            return null;
        }

        try {
            $eligibleIds = app(ApproverResolverService::class)
                ->resolveEligibleApproverIds($leave->user, $step);
        } catch (\Throwable) {
            return null;
        }

        if (count($eligibleIds) !== 1) {
            return null;
        }

        $approver = User::with('profile')->find($eligibleIds[0]);

        if ($approver === null) {
            return null;
        }

        $name = trim((string) ($approver->full_name_with_title ?: $approver->name));

        return $name !== '' ? $name : null;
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
     * แบบฟอร์มไหนพิมพ์ได้บ้างในสถานะนี้
     *
     * **แบบฟอร์มใบลาพิมพ์ได้ทุกสถานะโดยตั้งใจ** — ขั้นตอนราชการคือผู้ลาพิมพ์ใบลาออกมา
     * แล้วนำไปเสนอผู้บังคับบัญชาลงนาม ใบที่ยังรออนุมัติจึงเป็นกรณีที่ *ต้อง* พิมพ์ได้ที่สุด
     * ช่องลงนามที่ยังว่างอยู่คือสิ่งที่บอกเองว่าเอกสารยังไม่ผ่านการอนุมัติ
     *
     * ส่วนแบบฟอร์ม "ใบยกเลิกวันลา" เป็นคนละเอกสาร และมีได้ต่อเมื่อมีคำขอยกเลิกจริง
     */
    private function assertIssued(Leave $leave, bool $isCancellationForm): void
    {
        if (! $isCancellationForm) {
            return;
        }

        /*
         * ใบที่ผู้ยื่นถอนเองตั้งแต่ยังรออนุมัติ ไม่มีแบบฟอร์มใบยกเลิก เพราะไม่เคยมีเอกสาร
         * ที่อนุมัติแล้วให้ยกเลิก · เงื่อนไข `status === 'Cancelled'` คงไว้รองรับข้อมูลเก่า
         * ที่ยกเลิกไปก่อนระบบบันทึก `cancellation_status` — แต่ต้องเป็นใบที่ออกเอกสารแล้วจริง
         */
        abort_unless(
            $leave->hasCancellationRequest() || ($leave->status === 'Cancelled' && $leave->wasIssued()),
            422,
            'ใบลานี้ยังไม่มีคำขอยกเลิก จึงยังไม่มีแบบฟอร์มใบยกเลิกให้พิมพ์',
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

        $leave->load(['user.orgUnit', 'user.roles', 'user.profile', 'type', 'approvals.approver']);

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
                'Content-Disposition' => DocumentFilename::disposition(
                    $this->filenameFor($leave, $request->query('form') === 'cancel'),
                ),
            ]);
        }

        $lastLeave = Leave::where('user_id', $leave->user_id)
            ->where('leave_type_id', $leave->leave_type_id)
            ->where('status', 'Approved')
            ->where('id', '<', $leave->id)
            ->orderBy('id', 'desc')
            ->first();

        $settings = Setting::pluck('value', 'key');
        $isCancel = $request->query('form') === 'cancel';
        $approverBlocks = $this->approverBlocksFor($leave, $isCancel, $settings);

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

        /*
         * ตั้งชื่อไฟล์ด้วยตัวช่วยตัวเดียวกับเส้นทาง Document Engine เพื่อให้ผู้ใช้ได้ชื่อไฟล์
         * เหมือนกันไม่ว่าใบลาใบนั้นจะพิมพ์ด้วยเส้นทางไหน · ไม่ใช้ `$pdf->download()`
         * เพราะมันประกาศเฉพาะ `filename=` ธรรมดา ชื่อไทยจึงเพี้ยนบนบางเบราว์เซอร์
         */
        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => DocumentFilename::disposition($this->filenameFor($leave, $isCancel)),
        ]);
    }

    /**
     * Export a leave as Word.
     */
    public function exportWord(Request $request, Leave $leave, ThaiDateService $thaiDateService)
    {
        // SEC-003 — เช่นเดียวกับ exportPdf
        $this->authorize('view', $leave);
        $this->assertIssued($leave, $request->query('form') === 'cancel');

        $leave->load(['user.orgUnit', 'user.roles', 'user.profile', 'type', 'approvals.approver']);

        $lastLeave = Leave::where('user_id', $leave->user_id)
            ->where('leave_type_id', $leave->leave_type_id)
            ->where('status', 'Approved')
            ->where('id', '<', $leave->id)
            ->orderBy('id', 'desc')
            ->first();

        $settings = Setting::pluck('value', 'key');
        $isCancel = $request->query('form') === 'cancel';
        $approverBlocks = $this->approverBlocksFor($leave, $isCancel, $settings);

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

        $statSem1 = ['ป่วย' => ['used_times' => '', 'used_days' => ''], 'กิจ' => ['used_times' => '', 'used_days' => ''], 'คลอด' => ['used_times' => '', 'used_days' => '']];
        $statSem2 = ['ป่วย' => ['used_times' => '', 'used_days' => ''], 'กิจ' => ['used_times' => '', 'used_days' => ''], 'คลอด' => ['used_times' => '', 'used_days' => '']];

        // LEGACY-07 · 07-1 — เรนเดอร์จากเวอร์ชันเทมเพลตที่ใบลานี้ pin ไว้
        $html = app(LeaveDocumentTemplateResolver::class)->render(
            $leave,
            $viewName,
            compact('leave', 'thaiDateService', 'lastLeave', 'leaveBalance', 'settings', 'approverBlocks', 'statSem1', 'statSem2'),
        );
        $content = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"></head><body>'.$html.'</body></html>';

        /*
         * LS-07 — นามสกุลไฟล์กับชนิดไฟล์ต้องตรงกันเสมอ
         *
         * เอกสารที่ส่งออกทางนี้เป็น Word แบบ HTML (`.doc`) ซึ่งใช้เนื้อหาจากเวอร์ชันแบบฟอร์ม
         * ที่ใบลา pin ไว้ชุดเดียวกับ PDF จึงตรงกับเอกสารจริงเสมอ เดิมหน้าจอตั้งชื่อไฟล์เป็น
         * `.docx` และประกาศ MIME ของ DOCX ผู้ใช้จึงได้ไฟล์ที่นามสกุลไม่ตรงกับเนื้อใน
         *
         * ตอนนี้เซิร์ฟเวอร์เป็นผู้ประกาศทั้งชื่อไฟล์และชนิด และหน้าจอใช้ค่าจาก header นี้
         * (ดู `MyLeavesView.vue`) สัญญาสองฝั่งจึงไม่มีทางหลุดจากกันอีก
         */
        $filename = $this->filenameFor($leave, $isCancel, 'doc');

        return response($content)
            ->header('Content-Type', 'application/vnd.ms-word; charset=UTF-8')
            ->header('Content-Disposition', DocumentFilename::disposition($filename));
    }
}
