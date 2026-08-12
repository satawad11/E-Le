<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentSignature;
use App\Services\Saraban\AuditLogService;
use App\Services\Saraban\DocumentWorkflowService;
use App\Services\Saraban\PdfSignatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SignatureController extends Controller
{
    protected $workflowService;

    protected $auditLog;

    public function __construct(DocumentWorkflowService $workflowService, AuditLogService $auditLog)
    {
        $this->workflowService = $workflowService;
        $this->auditLog = $auditLog;
    }

    public function sign(Request $request, $id)
    {
        $document = Document::findOrFail($id);
        $this->authorize('sign', $document);

        $password = $request->input('password');
        $mode = $request->input('mode', 'esignature');

        try {
            // Every signature mode represents the same identity assertion. Never
            // let a client-selected mode bypass password confirmation.
            $hash = $this->workflowService->signDocument($document, $request->user(), $password, $request->ip(), $request->userAgent(), $mode);

            // Phase 3: Check if there's an attached PDF and stamp the signature
            $pdfAttachment = $document->attachments()->where('mime_type', 'application/pdf')->first();
            if ($pdfAttachment && $request->has('x') && $request->has('y')) {
                $pdfService = new PdfSignatureService;

                $userProfile = $request->user()->profile;
                $signatureImage = $userProfile && $userProfile->signature_path ? $userProfile->signature_path : null;
                $textToStamp = null;

                if ($mode === 'draw' && $request->has('signature_image')) {
                    // Save base64 image to temporary path or use directly
                    // the stampSignature function needs a path, so we'll save it to storage
                    $base64Image = $request->input('signature_image');
                    @[$type, $file_data] = explode(';', $base64Image);
                    @[, $file_data] = explode(',', $file_data);
                    $imageName = 'signatures/temp_'.time().'_'.Str::random(10).'.png';
                    Storage::disk('private_uploads')->put($imageName, base64_decode($file_data));
                    $signatureImage = $imageName;
                } elseif ($mode === 'text' && $request->has('text')) {
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
                    'text' => $textToStamp,
                ];

                $stampedPdfPath = $pdfService->stampSignature($pdfAttachment->path, $signatureImage, $options);

                // Update the attachment path or create a new one for the signed version
                // For simplicity, we create a new attachment record for the signed document
                $document->attachments()->create([
                    'name' => 'Signed_'.$pdfAttachment->name,
                    'type' => 'file',
                    'path' => $stampedPdfPath,
                    'mime_type' => 'application/pdf',
                    'uploaded_by' => $request->user()->id,
                ]);
            }

            $this->auditLog->log('document.signed', $document, [
                'mode' => $mode,
                'hash' => $hash,
            ]);

            return response()->json(['message' => 'Document signed successfully.', 'hash' => $hash]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Public QR verification. Confirms that a signature exists and is intact
     * without disclosing the contents of classified documents.
     */
    public function verify($hash)
    {
        $signature = DocumentSignature::with(['document', 'signer.profile'])
            ->where('signature_hash', $hash)
            ->first();

        if (! $signature || ! $signature->document) {
            return response()->json(['message' => 'Signature not found or invalid.'], 404);
        }

        $document = $signature->document;

        // A signature is only valid while the document it belongs to still exists
        // and has not been rolled back out of a signed state.
        $isValid = ! $document->trashed()
            && in_array($document->status, ['signed', 'dispatched', 'archived'], true)
            && is_string($signature->document_hash)
            && hash_equals($signature->document_hash, $this->workflowService->documentContentHash($document));

        $isClassified = $document->secrecy !== 'normal';

        $this->auditLog->log('signature.verified', $document, ['hash' => $hash]);

        return response()->json([
            'document' => $isClassified
                ? ['classified' => true]
                : [
                    'id' => $document->id,
                    'document_number' => $document->document_number,
                    'title' => $document->title,
                ],
            'signer' => [
                'name' => $signature->signer->name,
                'position' => $signature->signer->profile?->position,
            ],
            'signed_at' => $signature->signed_at,
            'is_valid' => $isValid,
        ]);
    }

    public function getQrCode($hash)
    {
        $verificationUrl = config('app.url').'/saraban/verify/'.$hash; // URL to frontend verify page
        $svg = $this->workflowService->generateVerificationQrCode($hash, $verificationUrl);

        return response($svg)->header('Content-Type', 'image/svg+xml');
    }
}
