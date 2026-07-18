<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentSignature;
use App\Services\Saraban\DocumentWorkflowService;
use Illuminate\Http\Request;

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
        
        $user = $request->user();
        $isSuperAdmin = $user->hasRole(['Super Admin', 'SuperAdmin']);
        $isSarabanOfficer = $user->hasRole(['Saraban Officer', 'เจ้าหน้าที่สารบัญ', 'admin_saraban']);
        $isManager = $user->hasRole(['Director', 'Deputy Director', 'Deputy', 'HR Head', 'HRHead']);
        $isCreator = $document->created_by === $user->id;

        if (!$isSuperAdmin && !$isSarabanOfficer && !$isManager && !$isCreator) {
            abort(403, 'คุณไม่มีสิทธิ์ลงนามในเอกสารนี้');
        }
        
        $password = $request->input('password');
        $mode = $request->input('mode', 'esignature');

        try {
            // Only verify password if mode is esignature
            $passwordToVerify = $mode === 'esignature' ? $password : null;
            $hash = $this->workflowService->signDocument($document, $request->user(), $passwordToVerify, $request->ip(), $request->userAgent(), $mode);
            
            // Phase 3: Check if there's an attached PDF and stamp the signature
            $pdfAttachment = $document->attachments()->where('mime_type', 'application/pdf')->first();
            if ($pdfAttachment && $request->has('x') && $request->has('y')) {
                $pdfService = new \App\Services\Saraban\PdfSignatureService();
                
                $userProfile = $request->user()->profile;
                $signatureImage = $userProfile && $userProfile->signature_path ? $userProfile->signature_path : null;
                $textToStamp = null;

                if ($mode === 'draw' && $request->has('signature_image')) {
                    // Save base64 image to temporary path or use directly
                    // the stampSignature function needs a path, so we'll save it to storage
                    $base64Image = $request->input('signature_image');
                    @list($type, $file_data) = explode(';', $base64Image);
                    @list(, $file_data) = explode(',', $file_data);
                    $imageName = 'signatures/temp_' . time() . '_' . Str::random(10) . '.png';
                    \Illuminate\Support\Facades\Storage::disk('public')->put($imageName, base64_decode($file_data));
                    $signatureImage = $imageName;
                } else if ($mode === 'text' && $request->has('text')) {
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
                
                // Update the attachment path or create a new one for the signed version
                // For simplicity, we create a new attachment record for the signed document
                $document->attachments()->create([
                    'name' => 'Signed_' . $pdfAttachment->name,
                    'type' => 'file',
                    'path' => $stampedPdfPath,
                    'mime_type' => 'application/pdf',
                    'uploaded_by' => $request->user()->id,
                ]);
            }
            
            return response()->json(['message' => 'Document signed successfully.', 'hash' => $hash]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function verify($hash)
    {
        $signature = DocumentSignature::with(['document', 'signer'])->where('signature_hash', $hash)->first();

        if (!$signature) {
            return response()->json(['message' => 'Signature not found or invalid.'], 404);
        }

        return response()->json([
            'document' => [
                'id' => $signature->document->id,
                'document_number' => $signature->document->document_number,
                'title' => $signature->document->title,
            ],
            'signer' => [
                'name' => $signature->signer->name,
                'position' => $signature->signer->profile?->position,
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
