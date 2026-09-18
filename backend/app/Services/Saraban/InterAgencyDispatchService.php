<?php

namespace App\Services\Saraban;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\DocumentVersion;
use App\Models\Saraban\SignatureEvidence;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TrustedClock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * บริการจัดทำแพ็กเกจส่งหนังสือราชการข้ามสถานศึกษา / หน่วยงาน (Inter-College & Inter-Agency Electronic Envelope Exchange)
 *
 * อ้างอิงตามระเบียบสำนักนายกรัฐมนตรีฯ และข้อกำหนดสถาปัตยกรรม SoT §17, §27:
 * - รวบรวมไฟล์ PDF, เอกสารแนบ, บันทึกการลงนามดิจิทัล (Digital Signature Evidence)
 * - สร้างไฟล์ manifest.json พร้อมคำนวณ Cryptographic SHA-512 Checksum
 * - รองรับการส่งผ่าน Webhook หรือดาวน์โหลดเป็น Electronic Evidence Package (.zip)
 * - รองรับการนำเข้าหนังสือจากแพ็กเกจหน่วยงานภายนอกพร้อมตรวจสอบความถูกต้องของแฮช
 */
class InterAgencyDispatchService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly TrustedClock $clock,
    ) {}

    /**
     * สร้างแพ็กเกจหนังสือราชการอิเล็กทรอนิกส์ (Electronic Evidence Package ZIP)
     */
    public function buildEvidencePackage(Document $document, User $dispatcher, array $options = []): array
    {
        $tempDir = storage_path('app/temp/dispatch');
        if (! file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $now = $this->clock->now();
        $docNo = $document->documentNumber?->number_full ?? $document->original_document_number ?? 'DOC-'.$document->id;
        $cleanDocNo = preg_replace('/[^A-Za-z0-9_\-\x{0E00}-\x{0E7F}]/u', '_', $docNo);
        $zipFilename = "saraban_package_{$cleanDocNo}_".$now->format('Ymd_His').'.zip';
        $zipPath = $tempDir.DIRECTORY_SEPARATOR.$zipFilename;

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('ไม่สามารถสร้างไฟล์แพ็กเกจ ZIP ได้');
        }

        $filesManifest = [];

        // 1. นำเข้าไฟล์ PDF หลักของหนังสือราชการ
        $currentVersion = $document->currentVersion ?? $document->versions()->latest('id')->first();
        if ($currentVersion && $currentVersion->pdf_file_path && Storage::disk('private_uploads')->exists($currentVersion->pdf_file_path)) {
            $mainPdfPath = Storage::disk('private_uploads')->path($currentVersion->pdf_file_path);
            $pdfContent = file_get_contents($mainPdfPath);
            $pdfSha512 = hash('sha512', $pdfContent);
            $zip->addFromString('document_main.pdf', $pdfContent);
            $filesManifest[] = [
                'name' => 'document_main.pdf',
                'role' => 'main_document',
                'size_bytes' => strlen($pdfContent),
                'sha512' => $pdfSha512,
            ];
        }

        // 2. รวบรวมบันทึกพยานหลักฐานการลงนามดิจิทัล (Signature Evidence)
        $signatures = SignatureEvidence::where('document_id', $document->id)->get();
        $signatureManifest = [];
        foreach ($signatures as $sig) {
            $sigData = [
                'signer_id' => $sig->user_id,
                'signer_name' => $sig->signer_name ?? $sig->user?->name ?? 'ผู้ลงนาม',
                'position' => $sig->signer_position ?? $sig->user?->position?->name ?? 'ตำแหน่ง',
                'role' => $sig->signature_role ?? 'signer',
                'signed_at' => $sig->signed_at ? $sig->signed_at->toIso8601String() : null,
                'signature_hash' => $sig->signature_hash,
                'document_content_hash' => $sig->document_hash,
            ];
            $signatureManifest[] = $sigData;
        }

        if (! empty($signatureManifest)) {
            $sigJson = json_encode($signatureManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $zip->addFromString('signatures/signature_evidence.json', $sigJson);
            $filesManifest[] = [
                'name' => 'signatures/signature_evidence.json',
                'role' => 'signature_evidence',
                'size_bytes' => strlen($sigJson),
                'sha512' => hash('sha512', $sigJson),
            ];
        }

        // 3. จัดทำ Manifest.json
        $manifest = [
            'version' => '1.0',
            'standard' => 'TH-GOV-SARABAN-E-DISPATCH',
            'created_at' => $this->clock->now()->toIso8601String(),
            'sender' => [
                'institution_code' => config('app.institution_code', 'VOC-COLLEGE-001'),
                'institution_name' => config('app.institution_name', 'สถานศึกษาอาชีวศึกษา'),
                'division' => $document->ownerOrgUnit?->name ?? 'ฝ่ายบริหารทรัพยากร',
                'dispatcher_name' => $dispatcher->name,
                'dispatcher_id' => $dispatcher->id,
            ],
            'target_recipient' => [
                'agency_name' => $options['target_agency_name'] ?? 'หน่วยงานปลายทาง',
                'note' => $options['note'] ?? null,
            ],
            'document' => [
                'document_id' => $document->id,
                'document_uuid' => $document->uuid,
                'document_number' => $docNo,
                'subject' => $document->subject,
                'summary' => $document->summary,
                'urgency' => $document->urgency,
                'confidentiality' => $document->confidentiality,
                'finalized_at' => $document->finalized_at ? $document->finalized_at->toIso8601String() : null,
                'content_hash' => $document->content_hash ?? $document->verification_hash,
            ],
            'files' => $filesManifest,
            'signatures' => $signatureManifest,
        ];

        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $zip->addFromString('manifest.json', $manifestJson);

        $zip->close();

        $packageSha512 = hash_file('sha512', $zipPath);

        $this->auditLogger->log(
            action: 'saraban.interagency.package_built',
            resource: $document,
            after: [
                'zip_filename' => $zipFilename,
                'sha512' => $packageSha512,
                'target_agency' => $options['target_agency_name'] ?? null,
            ],
            actorId: $dispatcher->id,
        );

        return [
            'zip_path' => $zipPath,
            'zip_filename' => $zipFilename,
            'manifest' => $manifest,
            'sha512' => $packageSha512,
            'size_bytes' => filesize($zipPath),
        ];
    }

    /**
     * ส่งแพ็กเกจหนังสือราชการไปยัง Webhook ปลายทางข้ามหน่วยงาน (Outbound Dispatch)
     */
    public function dispatchToWebhook(Document $document, string $webhookUrl, string $secretKey, User $dispatcher, array $options = []): array
    {
        $package = $this->buildEvidencePackage($document, $dispatcher, $options);
        $manifest = $package['manifest'];

        $payload = [
            'event' => 'saraban.document.dispatched',
            'timestamp' => $this->clock->now()->toIso8601String(),
            'manifest' => $manifest,
            'package_sha512' => $package['sha512'],
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha256', $payloadJson, $secretKey);

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'X-Saraban-Signature' => $signature,
                    'X-Saraban-Sender' => config('app.institution_code', 'VOC-COLLEGE-001'),
                    'Content-Type' => 'application/json',
                ])
                ->post($webhookUrl, $payload);

            $isSuccess = $response->successful();
            $statusCode = $response->status();
            $responseBody = $response->json() ?? $response->body();
        } catch (\Throwable $e) {
            $isSuccess = false;
            $statusCode = 0;
            $responseBody = $e->getMessage();
        }

        $this->auditLogger->log(
            action: 'saraban.interagency.dispatched_webhook',
            resource: $document,
            after: [
                'webhook_url' => $webhookUrl,
                'is_success' => $isSuccess,
                'status_code' => $statusCode,
                'package_sha512' => $package['sha512'],
            ],
            actorId: $dispatcher->id,
        );

        return [
            'success' => $isSuccess,
            'status_code' => $statusCode,
            'response' => $responseBody,
            'package_sha512' => $package['sha512'],
            'manifest' => $manifest,
        ];
    }

    /**
     * นำเข้าหนังสือราชการจากแพ็กเกจข้ามหน่วยงาน (Import Incoming Envelope Package)
     */
    public function importPackage(array $manifest, User $importer, OrgUnit $ownerUnit, ?UploadedFile $pdfFile = null): Document
    {
        if (empty($manifest['document']['subject'])) {
            throw new \InvalidArgumentException('ข้อมูลแพ็กเกจไม่สมบูรณ์: ไม่พบหัวข้อหนังสือ (subject)');
        }

        $docMeta = $manifest['document'];
        $senderMeta = $manifest['sender'] ?? [];

        $docTypeId = $docMeta['document_type_id'] ?? null;
        if (! $docTypeId) {
            $docType = DocumentType::where('category', DocumentType::CATEGORY_INCOMING)->first()
                ?? DocumentType::firstOrCreate(
                    ['code' => 'INCOMING_GENERAL'],
                    ['name' => 'หนังสือรับทั่วไป', 'category' => DocumentType::CATEGORY_INCOMING, 'is_active' => true]
                );
            $docTypeId = $docType->id;
        }

        $doc = Document::create([
            'document_type_id' => $docTypeId,
            'direction' => Document::DIRECTION_INCOMING,
            'subject' => $docMeta['subject'],
            'summary' => $docMeta['summary'] ?? ('หนังสือรับข้ามหน่วยงานจาก '.($senderMeta['institution_name'] ?? 'หน่วยงานภายนอก')),
            'urgency' => $docMeta['urgency'] ?? Document::URGENCY_NORMAL,
            'confidentiality' => $docMeta['confidentiality'] ?? Document::CONFIDENTIALITY_NORMAL,
            'status' => Document::STATUS_DRAFT,
            'owner_org_unit_id' => $ownerUnit->id,
            'created_by' => $importer->id,
            'verification_hash' => $docMeta['content_hash'] ?? hash('sha512', json_encode($manifest)),
        ]);

        if ($pdfFile && $pdfFile->isValid()) {
            $path = $pdfFile->store('saraban/documents', 'private_uploads');
            $fileHash = hash_file('sha512', Storage::disk('private_uploads')->path($path));

            $version = DocumentVersion::create([
                'document_id' => $doc->id,
                'version_no' => 1,
                'stage' => DocumentVersion::STAGE_ORIGINAL,
                'pdf_file_path' => $path,
                'file_hash' => $fileHash,
                'created_by' => $importer->id,
            ]);

            $doc->update(['current_version_id' => $version->id]);
        }

        $this->auditLogger->log(
            action: 'saraban.interagency.imported',
            resource: $doc,
            after: [
                'source_institution' => $senderMeta['institution_name'] ?? null,
                'original_doc_number' => $docMeta['document_number'] ?? null,
                'manifest_version' => $manifest['version'] ?? '1.0',
            ],
            actorId: $importer->id,
        );

        return $doc;
    }
}
