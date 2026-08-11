<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentSignature;
use App\Services\Saraban\AuditLogger;
use App\Services\Saraban\DocumentWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SignatureController extends Controller
{
    protected $workflowService;

    public function __construct(DocumentWorkflowService $workflowService)
    {
        $this->workflowService = $workflowService;
    }

    public function sign(Request $request, $id)
    {
        $document = Document::findOrFail($id);

        $this->authorize('sign', $document);

        $request->validate([
            'mode' => 'nullable|in:esignature,draw,text',
            'password' => 'nullable|string',
            'signature_image' => 'nullable|string',
            'text' => 'nullable|string|max:255',
            'x' => 'nullable|numeric',
            'y' => 'nullable|numeric',
            'page' => 'nullable|integer|min:1',
        ]);

        $mode = $request->input('mode', 'esignature');

        try {
            // การยืนยันรหัสผ่านบังคับทุกโหมด — ส่ง password เข้า service เสมอ
            $hash = $this->workflowService->signDocument(
                $document,
                $request->user(),
                $request->input('password'),
                $request->ip(),
                $request->userAgent(),
                $mode
            );

            // Phase 3: Check if there's an attached PDF and stamp the signature
            $pdfAttachment = $document->attachments()->where('mime_type', 'application/pdf')->first();
            if ($pdfAttachment && $request->has('x') && $request->has('y')) {
                $pdfService = new \App\Services\Saraban\PdfSignatureService();

                $userProfile = $request->user()->profile;
                $signatureImage = $userProfile && $userProfile->signature_path ? $userProfile->signature_path : null;
                $textToStamp = null;

                if ($mode === 'draw' && $request->filled('signature_image')) {
                    // Save base64 image to a temporary path — stampSignature() expects a path
                    $base64Image = $request->input('signature_image');
                    @list($type, $file_data) = explode(';', $base64Image);
                    @list(, $file_data) = explode(',', $file_data);
                    $signatureImage = app(\App\Services\Saraban\AttachmentStorage::class)
                        ->putContents('temp_sig_' . time() . '_' . Str::random(10) . '.png', base64_decode($file_data));
                } else if ($mode === 'text' && $request->filled('text')) {
                    $signatureImage = null; // No image, just text
                    $textToStamp = $request->input('text');
                }

                $options = [
                    'x' => $request->input('x'),
                    'y' => $request->input('y'),
                    'page' => $request->input('page', 1),
                    'signer_name' => $request->user()->name,
                    'timestamp' => now()->format('Y-m-d H:i:s'),
                    'hash' => $hash,
                    'text' => $textToStamp
                ];

                $stampedPdfPath = $pdfService->stampSignature($pdfAttachment->path, $signatureImage, $options);

                // For simplicity, we create a new attachment record for the signed document
                $stamped = $document->attachments()->create([
                    'name' => 'Signed_' . $pdfAttachment->name,
                    'type' => 'file',
                    'path' => $stampedPdfPath,
                    'mime_type' => 'application/pdf',
                    'uploaded_by' => $request->user()->id,
                ]);

                AuditLogger::log('document.signature_stamped', $document, [
                    'attachment_id' => $stamped->id,
                    'source_attachment_id' => $pdfAttachment->id,
                ]);
            }

            return response()->json(['message' => 'Document signed successfully.', 'hash' => $hash]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function verify($hash)
    {
        $signature = DocumentSignature::with(['document', 'signer'])->where('signature_hash', $hash)->first();

        if (!$signature || !$signature->document) {
            return response()->json(['message' => 'Signature not found or invalid.'], 404);
        }

        $document = $signature->document;
        $isClassified = $document->secrecy !== 'normal';

        AuditLogger::log('document.signature_verified', $document, ['hash' => $hash]);

        return response()->json([
            'document' => [
                'id' => $document->id,
                'document_number' => $document->document_number,
                // endpoint สาธารณะ — ห้ามเปิดเผยชื่อเรื่องของหนังสือชั้นความลับ
                'title' => $isClassified ? 'หนังสือชั้นความลับ (ไม่เปิดเผยรายละเอียด)' : $document->title,
                'is_classified' => $isClassified,
            ],
            'signer' => [
                'name' => $signature->signer?->name,
                'position' => $signature->signer?->profile?->position,
            ],
            'signed_at' => $signature->signed_at,
            'is_valid' => true
        ]);
    }

    public function getQrCode($hash)
    {
        $verificationUrl = config('app.url') . '/saraban/verify/' . $hash; // URL to frontend verify page
        $svg = $this->workflowService->generateVerificationQrCode($hash, $verificationUrl);
        return response($svg)->header('Content-Type', 'image/svg+xml');
    }
}
