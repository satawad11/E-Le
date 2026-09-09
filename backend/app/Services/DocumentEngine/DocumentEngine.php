<?php

namespace App\Services\DocumentEngine;

use App\Models\Document\DocumentGeneratedFile;
use App\Models\Document\DocumentPrintCalibration;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * จุดเข้าเดียวของการออกเอกสารจาก Document Engine
 *
 * ลำดับการทำงาน:
 *   binding rule → template → version → data provider → field mapping → renderer → snapshot
 *
 * Engine ไม่รู้จัก E-Leave โดยตรง — รู้เพียง module/entity_type/entity_id และ data source code
 * โมดูลอื่นจึงนำไปใช้ได้โดยเพิ่ม provider กับ binding rule เท่านั้น
 */
class DocumentEngine
{
    public function __construct(
        private readonly TemplateResolver $resolver,
        private readonly DataProviderRegistry $providers,
        private readonly PdfOverlayRenderer $renderer,
    ) {}

    /**
     * ออกเอกสารหนึ่งฉบับ
     *
     * @param  array<string,mixed>  $context  ข้อมูลที่ provider ต้องใช้ (เช่น ['leave' => $leave])
     */
    public function generate(
        string $module,
        string $entityType,
        ?int $entityId,
        string $dataSource,
        array $context,
        ?int $pinnedVersionId = null,
        ?User $actor = null,
    ): DocumentResult {
        $template = $this->resolver->resolveTemplate($module, $entityType, $entityId);
        $version = $this->resolver->versionForDocument($pinnedVersionId, $template);

        if ($version === null) {
            throw new \RuntimeException('ยังไม่ได้ผูกแบบฟอร์มกับรายการนี้ กรุณาตั้งค่าที่ศูนย์จัดการเอกสาร');
        }

        $values = $this->providers->get($dataSource)->fields($context);
        $calibration = $this->calibrationFor($template, $actor);

        $content = $this->renderer->render($version, $values, $calibration);

        return new DocumentResult($template, $version, $content, $values);
    }

    /**
     * บันทึกเอกสารที่ออกแล้วเป็นไฟล์ถาวร
     *
     * ฉบับที่ finalize แล้วห้ามเขียนทับ — การออกซ้ำจะกลายเป็น revision ถัดไปเสมอ
     * และฉบับก่อนหน้าถูกทำเป็น SUPERSEDED โดยไม่ลบไฟล์ทิ้ง
     */
    public function store(
        DocumentResult $result,
        string $module,
        string $sourceType,
        string $sourceId,
        bool $finalize = false,
        ?User $actor = null,
    ): DocumentGeneratedFile {
        return DB::transaction(function () use ($result, $module, $sourceType, $sourceId, $finalize, $actor) {
            $previous = DocumentGeneratedFile::where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->orderByDesc('revision')
                ->lockForUpdate()
                ->first();

            $revision = ($previous?->revision ?? 0) + 1;
            $path = sprintf('documents/%s/%s/%s-r%d.pdf', strtolower($module), $sourceType, $sourceId, $revision);

            Storage::disk('private_uploads')->put($path, $result->content);

            $file = DocumentGeneratedFile::create([
                'module' => $module,
                'document_type' => $result->template?->document_type ?? 'FORM',
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'document_template_id' => $result->template?->id,
                'document_template_version_id' => $result->version->id,
                'revision' => $revision,
                'file_path' => $path,
                'mime_type' => 'application/pdf',
                'file_size' => strlen($result->content),
                'sha256' => hash('sha256', $result->content),
                'status' => $finalize ? DocumentGeneratedFile::STATUS_FINALIZED : DocumentGeneratedFile::STATUS_GENERATED,
                'snapshot' => $result->values,
                'generated_by' => $actor?->id,
                'generated_at' => now(),
                'finalized_at' => $finalize ? now() : null,
            ]);

            if ($previous !== null && $previous->status !== DocumentGeneratedFile::STATUS_SUPERSEDED) {
                // ฉบับก่อนหน้าไม่ถูกลบ เพียงแต่ไม่ใช่ฉบับปัจจุบันอีกต่อไป
                $previous->forceFill(['status' => DocumentGeneratedFile::STATUS_SUPERSEDED])->save();
            }

            $this->audit($finalize ? 'document_finalized' : 'document_generated', $file, $actor);

            return $file;
        }, 5);
    }

    /** ฉบับล่าสุดของเอกสารนี้ (ถ้ามี) — ใช้ตอบคำขอเปิดเอกสารย้อนหลัง */
    public function latestFor(string $sourceType, string $sourceId): ?DocumentGeneratedFile
    {
        return DocumentGeneratedFile::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->orderByDesc('revision')
            ->first();
    }

    /**
     * ค่าชดเชยเครื่องพิมพ์: global → template → ผู้ใช้ (ค่าที่เจาะจงกว่าอยู่ทับ)
     *
     * @return array{x_offset: float, y_offset: float, scale: float}
     */
    public function calibrationFor(?DocumentTemplate $template, ?User $user): array
    {
        $result = ['x_offset' => 0.0, 'y_offset' => 0.0, 'scale' => 1.0];

        $rows = DocumentPrintCalibration::query()
            ->where('is_active', true)
            ->where(function ($query) use ($template, $user) {
                $query->where('scope', DocumentPrintCalibration::SCOPE_GLOBAL);

                if ($template !== null) {
                    $query->orWhere(fn ($sub) => $sub->where('scope', DocumentPrintCalibration::SCOPE_TEMPLATE)
                        ->where('document_template_id', $template->id));
                }

                if ($user !== null) {
                    $query->orWhere(fn ($sub) => $sub->where('scope', DocumentPrintCalibration::SCOPE_USER)
                        ->where('user_id', $user->id));
                }
            })
            ->get()
            ->sortBy(fn (DocumentPrintCalibration $row) => match ($row->scope) {
                DocumentPrintCalibration::SCOPE_GLOBAL => 0,
                DocumentPrintCalibration::SCOPE_TEMPLATE => 1,
                default => 2,
            });

        foreach ($rows as $row) {
            $result = [
                'x_offset' => (float) $row->x_offset,
                'y_offset' => (float) $row->y_offset,
                'scale' => (float) $row->scale,
            ];
        }

        return $result;
    }

    /**
     * ตัวอย่างเอกสารสำหรับหน้าออกแบบ — ไม่แตะข้อมูลจริงของผู้ใด
     *
     * `sample` = ข้อความไทยยาวตามกรณีจริง เพื่อตรวจว่าช่องพอหรือไม่
     * `blank`  = วาดเฉพาะกรอบของช่อง เพื่อเทียบกับเส้นบนแบบฟอร์มต้นฉบับ
     */
    public function preview(DocumentTemplateVersion $version, string $mode = 'sample'): string
    {
        if ($mode === 'blank') {
            return $this->renderer->render($version, [], [], outline: true);
        }

        return $this->renderer->render($version, app(PreviewSampleValues::class)->forMappings($version->fieldMappings));
    }

    /**
     * ตัวอย่างเอกสารจากข้อมูลจริงหนึ่งฉบับ (อ่านอย่างเดียว)
     *
     * ใช้ตรวจของจริง เช่น ชื่อยาวจริง เหตุผลจริง และจำนวนขั้นอนุมัติจริง โดยไม่บันทึกไฟล์
     * ผู้เรียกต้องตรวจสิทธิ์การเปิดใบลาฉบับนั้นมาก่อนแล้ว
     *
     * @param  array<string,mixed>  $context
     */
    public function previewWithData(DocumentTemplateVersion $version, string $dataSource, array $context): string
    {
        return $this->renderer->render($version, $this->providers->get($dataSource)->fields($context));
    }

    private function audit(string $action, DocumentGeneratedFile $file, ?User $actor): void
    {
        try {
            app(AuditLogger::class)->log(
                action: $action,
                resource: 'DocumentGeneratedFile',
                resourceId: $file->id,
                after: [
                    'source_type' => $file->source_type,
                    'source_id' => $file->source_id,
                    'revision' => $file->revision,
                    'sha256' => $file->sha256,
                    'template_version_id' => $file->document_template_version_id,
                ],
                actorId: $actor?->id,
            );
        } catch (\Throwable $exception) {
            // การบันทึกประวัติล้มเหลวต้องไม่ทำให้การออกเอกสารล้มตาม
            report($exception);
        }
    }
}
