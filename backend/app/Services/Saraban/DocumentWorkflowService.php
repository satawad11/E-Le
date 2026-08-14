<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentSignature;
use App\Models\Saraban\DocumentWorkflowStep;
use App\Models\User;
use App\Notifications\SarabanDocumentNotification;
use App\Services\Workflow\SarabanWorkflowProvisioner;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class DocumentWorkflowService
{
    /**
     * Update the workflow state of a document.
     */
    public function transitionState(Document $document, string $newState, int $userId, string $action, ?string $comment = null, ?string $ip = null)
    {
        $result = DB::transaction(function () use ($document, $newState, $userId, $action, $comment, $ip) {
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

            return $document;
        });

        /*
         * CORE-WF-002 — ผูกเอกสารเข้ากับ workflow version ที่ pin ไว้
         *
         * `saraban_document_workflow_steps` ยังเป็นประวัติจริงและตรรกะด้านบน
         * ยังเป็นผู้ตัดสินสถานะทั้งหมด ส่วนนี้เพียงขยับ instance ให้ตรงกัน
         * เพื่อให้การแก้สายงานภายหลังไม่ย้อนไปเปลี่ยนเอกสารที่กำลังเดินอยู่
         *
         * ห่อ try/catch ไว้เพราะการผูก workflow ต้องไม่ทำให้การรับ-ส่งหนังสือล้ม
         */
        if (config('workflow.saraban_pinning', true)) {
            try {
                app(SarabanWorkflowProvisioner::class)->syncState($document, $newState, $userId, $comment);
            } catch (\Throwable $exception) {
                Log::warning('ผูก workflow ของหนังสือราชการไม่สำเร็จ', [
                    'document_id' => $document->id,
                    'status' => $newState,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Submit a document for review/approval.
     */
    public function submitDocument(Document $document, int $userId, ?string $comment = null, ?string $ip = null)
    {
        $result = $this->transitionState($document, 'pending_review', $userId, 'submitted', $comment, $ip);

        // Notify the registrars. Selected by permission rather than by a role name
        // string, because the role that holds it differs between installs.
        $officers = User::permission('saraban.document.register')->get();
        Notification::send($officers, new SarabanDocumentNotification(
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
            $document->creator->notify(new SarabanDocumentNotification(
                $document->id,
                'เอกสารได้รับการอนุมัติ',
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
            $document->creator->notify(new SarabanDocumentNotification(
                $document->id,
                'เอกสารถูกตีกลับ',
                "เอกสาร {$document->title} ของคุณถูกตีกลับ: {$comment}",
                'rejected'
            ));
        }

        return $result;
    }

    /**
     * Sign a document electronically.
     */
    public function signDocument(Document $document, User $user, ?string $password = null, ?string $ip = null, ?string $userAgent = null, ?string $mode = null)
    {
        if (! $password || ! Hash::check($password, $user->password)) {
            throw new \Exception('รหัสผ่านไม่ถูกต้อง ไม่สามารถลงนามได้');
        }

        return DB::transaction(function () use ($document, $user, $ip, $userAgent, $mode) {
            $hashString = $document->id.'-'.$user->id.'-'.now()->timestamp.'-'.Str::random(10);
            $signatureHash = hash('sha256', $hashString);

            DocumentSignature::create([
                'document_id' => $document->id,
                'signer_id' => $user->id,
                'signature_hash' => $signatureHash,
                'document_hash' => $this->documentContentHash($document),
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'mode' => $mode ?: 'esignature',
                'signed_at' => now(),
            ]);

            // Change status to signed
            $this->transitionState($document, 'signed', $user->id, 'signed', null, $ip);

            return $signatureHash;
        });
    }

    public function documentContentHash(Document $document): string
    {
        $fields = [
            'document_number', 'title', 'type_id', 'priority', 'secrecy',
            'created_by', 'department_id', 'document_date', 'to', 'reference',
            'attachments_note', 'remark', 'incoming_document_number', 'incoming_date',
            'creation_mode', 'content', 'template_type',
        ];

        return hash('sha256', json_encode(
            collect($fields)->mapWithKeys(fn (string $field) => [$field => $document->getAttribute($field)])->all(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    /**
     * Generate QR Code SVG string for verification.
     */
    public function generateVerificationQrCode(string $signatureHash, string $verificationUrl)
    {
        // php-qrcode v6 เลิกใช้ค่าคงที่ OUTPUT_*/ECC_* แล้ว ต้องระบุ outputInterface แทน
        $options = new QROptions([
            'version' => 5,
            'outputInterface' => QRMarkupSVG::class,
            'eccLevel' => EccLevel::L,
            // controller ส่งกลับเป็น image/svg+xml จึงต้องได้ SVG ดิบ ไม่ใช่ data URI
            'outputBase64' => false,
            'svgUseFillAttributes' => false,
        ]);

        $qrcode = new QRCode($options);

        return $qrcode->render($verificationUrl);
    }
}
