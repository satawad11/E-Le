<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentWorkflowStep;
use App\Models\Saraban\DocumentSignature;
use App\Models\Setting;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentWorkflowService
{
    /**
     * State machine ตาม CLAUDE.md หัวข้อ 5
     * draft → pending_review → pending_approval → approved_pending_signature
     * → signed → dispatched → archived (ทุกจุด reject กลับ draft ได้)
     */
    public const TRANSITIONS = [
        'draft' => ['pending_review'],
        'pending_review' => ['pending_approval', 'draft'],
        'pending_approval' => ['approved_pending_signature', 'draft'],
        'approved_pending_signature' => ['signed', 'draft'],
        'signed' => ['dispatched', 'archived'],
        'dispatched' => ['archived', 'signed'],
        'archived' => [],
    ];

    /**
     * สถานะที่ยังลงนามได้ — รวม dispatched เพื่อรองรับหนังสือที่ลงทะเบียนรับเข้ามา
     * แล้วค่อยเสนอลงนามภายหลัง (flow ปัจจุบันของหน้าลงทะเบียน)
     */
    public const SIGNABLE_STATES = [
        'approved_pending_signature',
        'dispatched',
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    protected function assertTransition(Document $document, string $newState): void
    {
        $from = $document->status ?? 'draft';

        if ($from === $newState) {
            return;
        }

        if (!$this->canTransition($from, $newState)) {
            throw ValidationException::withMessages([
                'status' => "ไม่สามารถเปลี่ยนสถานะเอกสารจาก \"{$from}\" เป็น \"{$newState}\" ได้",
            ]);
        }
    }

    /**
     * Update the workflow state of a document.
     */
    public function transitionState(Document $document, string $newState, int $userId, string $action, ?string $comment = null, ?string $ip = null)
    {
        $this->assertTransition($document, $newState);

        return DB::transaction(function () use ($document, $newState, $userId, $action, $comment, $ip) {
            $previousState = $document->status;

            // Get current max step order
            $lastStep = DocumentWorkflowStep::where('document_id', $document->id)
                ->orderBy('step_order', 'desc')
                ->first();

            $stepOrder = $lastStep ? $lastStep->step_order + 1 : 1;

            // Record workflow step
            DocumentWorkflowStep::create([
                'document_id' => $document->id,
                'user_id' => $userId,
                'action' => $action,
                'comment' => $comment,
                'action_at' => now(),
                'step_order' => $stepOrder,
                'ip_address' => $ip,
            ]);

            // Update document status
            $document->update(['status' => $newState]);

            AuditLogger::log('document.' . $action, $document, [
                'from' => $previousState,
                'to' => $newState,
                'comment' => $comment,
            ], $userId);

            return $document;
        });
    }

    /**
     * Submit a document for review/approval.
     */
    public function submitDocument(Document $document, int $userId, ?string $comment = null, ?string $ip = null)
    {
        $result = $this->transitionState($document, 'pending_review', $userId, 'submitted', $comment, $ip);

        // Notify Saraban Officers (example)
        $officers = \App\Models\User::role('Saraban Officer')->get();
        \Illuminate\Support\Facades\Notification::send($officers, new \App\Notifications\SarabanDocumentNotification(
            $document->id,
            "เอกสารรอตรวจสอบ: {$document->title}",
            "เอกสาร {$document->document_number} ถูกส่งเพื่อรอการตรวจสอบ",
            'submitted'
        ));

        return $result;
    }

    /**
     * Approve a document.
     */
    public function approveDocument(Document $document, int $userId, ?string $comment = null, ?string $ip = null)
    {
        $newState = $document->status === 'pending_review' ? 'pending_approval' : 'approved_pending_signature';
        $result = $this->transitionState($document, $newState, $userId, 'approved', $comment, $ip);

        if ($document->creator) {
            $document->creator->notify(new \App\Notifications\SarabanDocumentNotification(
                $document->id,
                "เอกสารได้รับการอนุมัติ",
                "เอกสาร {$document->title} ของคุณได้รับการอนุมัติแล้ว",
                'approved'
            ));
        }

        return $result;
    }

    /**
     * Reject a document back to draft.
     */
    public function rejectDocument(Document $document, int $userId, string $comment, ?string $ip = null)
    {
        $result = $this->transitionState($document, 'draft', $userId, 'rejected', $comment, $ip);

        if ($document->creator) {
            $document->creator->notify(new \App\Notifications\SarabanDocumentNotification(
                $document->id,
                "เอกสารถูกตีกลับ",
                "เอกสาร {$document->title} ของคุณถูกตีกลับ: {$comment}",
                'rejected'
            ));
        }

        return $result;
    }

    /**
     * Sign a document electronically.
     *
     * การยืนยันตัวตนก่อนลงนาม (setting `require_pin_for_signature`) บังคับกับ
     * ทุกโหมดการลงนาม — ห้ามข้ามด้วยการเลือก mode อื่น
     */
    public function signDocument(Document $document, \App\Models\User $user, ?string $password = null, ?string $ip = null, ?string $userAgent = null, string $mode = 'esignature')
    {
        if (!in_array($document->status, self::SIGNABLE_STATES, true)) {
            throw ValidationException::withMessages([
                'status' => "เอกสารสถานะ \"{$document->status}\" ไม่สามารถลงนามได้",
            ]);
        }

        $requirePin = Setting::where('key', 'require_pin_for_signature')->first();
        $isRequirePin = $requirePin && $requirePin->value === '1';

        if ($isRequirePin) {
            if (!$password || !Hash::check($password, $user->password)) {
                AuditLogger::log('document.sign_failed', $document, ['reason' => 'invalid_password', 'mode' => $mode], $user->id);

                throw new \Exception('รหัสผ่านไม่ถูกต้อง ไม่สามารถลงนามได้');
            }
        }

        return DB::transaction(function () use ($document, $user, $ip, $userAgent, $mode) {
            $hashString = $document->id . '-' . $user->id . '-' . now()->timestamp . '-' . Str::random(10);
            $signatureHash = hash('sha256', $hashString);

            DocumentSignature::create([
                'document_id' => $document->id,
                'signer_id' => $user->id,
                'signature_hash' => $signatureHash,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'signed_at' => now(),
            ]);

            // Change status to signed
            $this->transitionState($document, 'signed', $user->id, 'signed', null, $ip);

            AuditLogger::log('document.signed', $document, [
                'mode' => $mode,
                'signature_hash' => $signatureHash,
            ], $user->id);

            return $signatureHash;
        });
    }

    /**
     * Generate QR Code SVG string for verification.
     */
    public function generateVerificationQrCode(string $signatureHash, string $verificationUrl)
    {
        $options = new QROptions([
            'version'      => 5,
            'outputType'   => QRCode::OUTPUT_MARKUP_SVG,
            'eccLevel'     => QRCode::ECC_L,
        ]);

        $qrcode = new QRCode($options);
        return $qrcode->render($verificationUrl);
    }
}
