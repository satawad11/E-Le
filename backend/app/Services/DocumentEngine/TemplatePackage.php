<?php

namespace App\Services\DocumentEngine;

use App\Models\Document\DocumentFieldMapping;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * นำเข้า/ส่งออก "ชุดแบบฟอร์ม" เป็นไฟล์เดียว
 *
 * แบบฟอร์มหนึ่งฉบับประกอบด้วยไฟล์ต้นฉบับ PDF กับตำแหน่งฟิลด์ทั้งชุด การย้ายข้ามเครื่อง
 * หรือข้ามสถานศึกษาเดิมทำได้เฉพาะผ่านคำสั่งบรรทัดคำสั่งของผู้พัฒนา
 *
 * รูปแบบแพ็กเกจเป็น ZIP ที่มีสองไฟล์ชื่อคงที่เท่านั้น:
 *   - `manifest.json` — ข้อมูลแม่แบบ ค่าหน้ากระดาษ และตำแหน่งฟิลด์
 *   - `form.pdf`      — ไฟล์แบบฟอร์มต้นฉบับ
 *
 * ตอนนำเข้าอ่านเฉพาะสองชื่อนี้ **ไม่แตกไฟล์ตามเส้นทางในไฟล์บีบอัด** จึงไม่มีทางเกิด
 * การเขียนไฟล์ออกนอกที่เก็บ (Zip Slip) และไฟล์ที่ได้ต้องผ่านการเปิดอ่านด้วยตัวอ่าน PDF จริงก่อน
 * เอกสารที่ออกไปแล้วไม่ถูกแตะ — การนำเข้าสร้าง "แม่แบบใหม่พร้อมฉบับร่าง" เสมอ
 */
class TemplatePackage
{
    private const MANIFEST = 'manifest.json';

    private const FORM = 'form.pdf';

    public function __construct(private readonly TemplateGeometry $geometry) {}

    /** สร้างไฟล์แพ็กเกจของเวอร์ชันที่ระบุ คืน path ของไฟล์ชั่วคราว */
    public function export(DocumentTemplateVersion $version): string
    {
        $disk = Storage::disk('private_uploads');
        $template = $version->template;

        $manifest = [
            'format' => 'e-leave.document-template',
            'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'template' => [
                'code' => $template->code,
                'name' => $template->name,
                'module' => $template->module,
                'document_type' => $template->document_type,
                'render_mode' => $version->render_mode,
                'description' => $template->description,
            ],
            'page' => [
                'paper_size' => $version->paper_size,
                'orientation' => $version->orientation,
                'margins' => [
                    'top' => (float) $version->margin_top,
                    'bottom' => (float) $version->margin_bottom,
                    'left' => (float) $version->margin_left,
                    'right' => (float) $version->margin_right,
                ],
                'sizes' => $this->geometry->pageSizes($version),
            ],
            'mappings' => $version->fieldMappings()->orderBy('page')->orderBy('id')->get()->map(fn ($mapping) => [
                'field_code' => $mapping->field_code,
                'field_type' => $mapping->field_type,
                'page' => (int) $mapping->page,
                'x' => (float) $mapping->x,
                'y' => (float) $mapping->y,
                'width' => (float) $mapping->width,
                'height' => (float) $mapping->height,
                'font' => $mapping->font,
                'font_size' => (float) $mapping->font_size,
                'align' => $mapping->align,
                'overflow' => $mapping->overflow,
                'format' => $mapping->format,
                'static_text' => $mapping->static_text,
                'options' => $mapping->options,
            ])->all(),
        ];

        $path = tempnam(sys_get_temp_dir(), 'tplpkg').'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString(self::MANIFEST, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if (filled($version->pdf_file_path) && $disk->exists($version->pdf_file_path)) {
            $zip->addFromString(self::FORM, $disk->get($version->pdf_file_path));
        }

        $zip->close();

        return $path;
    }

    /**
     * นำเข้าแพ็กเกจเป็นแม่แบบใหม่พร้อมฉบับร่างหนึ่งเวอร์ชัน
     *
     * ไม่เขียนทับแม่แบบที่มีอยู่เด็ดขาด — รหัสที่ซ้ำจะถูกต่อท้ายให้ไม่ชนกัน
     * เพื่อไม่ให้การนำเข้าที่ผิดฉบับไปเปลี่ยนแบบฟอร์มที่ใช้งานอยู่
     */
    public function import(string $zipPath, ?User $actor = null): DocumentTemplateVersion
    {
        $zip = new \ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('เปิดไฟล์แพ็กเกจไม่ได้ — ต้องเป็นไฟล์ ZIP ที่ส่งออกจากระบบนี้');
        }

        try {
            $manifestJson = $zip->getFromName(self::MANIFEST);
            $form = $zip->getFromName(self::FORM);
        } finally {
            $zip->close();
        }

        if ($manifestJson === false) {
            throw new \RuntimeException('ไฟล์แพ็กเกจไม่มี manifest.json');
        }

        $manifest = json_decode((string) $manifestJson, true);

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== 'e-leave.document-template') {
            throw new \RuntimeException('ไฟล์แพ็กเกจไม่ใช่รูปแบบแบบฟอร์มของระบบนี้');
        }

        if ($form === false || ! str_starts_with((string) $form, '%PDF')) {
            throw new \RuntimeException('ไฟล์แพ็กเกจไม่มีแบบฟอร์มต้นฉบับที่เป็น PDF');
        }

        $disk = Storage::disk('private_uploads');
        $storedPath = 'document-templates/imported-'.Str::random(24).'.pdf';
        $disk->put($storedPath, $form);

        return DB::transaction(function () use ($manifest, $storedPath, $actor, $disk) {
            $template = DocumentTemplate::create([
                'code' => $this->uniqueCode((string) ($manifest['template']['code'] ?? 'imported_form')),
                'name' => (string) ($manifest['template']['name'] ?? 'แบบฟอร์มที่นำเข้า'),
                'module' => (string) ($manifest['template']['module'] ?? 'leave'),
                'document_type' => (string) ($manifest['template']['document_type'] ?? 'FORM'),
                'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
                'description' => $manifest['template']['description'] ?? null,
                'paper_size' => (string) ($manifest['page']['paper_size'] ?? 'A4'),
                'orientation' => (string) ($manifest['page']['orientation'] ?? 'portrait'),
                'content_html' => '',
                'is_active' => true,
                'created_by' => $actor?->id,
            ]);

            // แม่แบบที่เพิ่งสร้างมีเวอร์ชันแรกจาก model hook อยู่แล้ว จึงใช้เวอร์ชันนั้นเป็นฉบับร่าง
            $version = $template->versions()->orderByDesc('version')->first();

            $version->update([
                'status' => DocumentTemplateVersion::STATUS_DRAFT,
                'is_active' => false,
                'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
                'pdf_file_path' => $storedPath,
                'page_count' => count($this->pageSizesOf($storedPath, $disk)),
                'created_by' => $actor?->id,
            ]);

            foreach ((array) ($manifest['mappings'] ?? []) as $mapping) {
                $version->fieldMappings()->create([
                    'field_code' => (string) ($mapping['field_code'] ?? ''),
                    'field_type' => in_array($mapping['field_type'] ?? '', DocumentFieldMapping::RENDERABLE_TYPES, true)
                        ? $mapping['field_type']
                        : 'TEXT',
                    'page' => max(1, (int) ($mapping['page'] ?? 1)),
                    'x' => (float) ($mapping['x'] ?? 0),
                    'y' => (float) ($mapping['y'] ?? 0),
                    'width' => (float) ($mapping['width'] ?? 100),
                    'height' => (float) ($mapping['height'] ?? 18),
                    'font' => $mapping['font'] ?? null,
                    'font_size' => (float) ($mapping['font_size'] ?? 14),
                    'align' => in_array($mapping['align'] ?? '', ['left', 'center', 'right'], true) ? $mapping['align'] : 'left',
                    'overflow' => in_array($mapping['overflow'] ?? '', ['shrink', 'wrap', 'clip'], true) ? $mapping['overflow'] : 'shrink',
                    'format' => isset(ValueFormatter::FORMATS[$mapping['format'] ?? '']) ? $mapping['format'] : null,
                    'static_text' => $mapping['static_text'] ?? null,
                    'options' => $this->safeOptions($mapping['options'] ?? null),
                    'is_active' => true,
                ]);
            }

            return $version->fresh();
        }, 5);
    }

    /** ขนาดหน้าของไฟล์ที่เพิ่งเก็บ — ใช้ตรวจว่าเปิดอ่านได้จริงด้วย */
    private function pageSizesOf(string $path, $disk): array
    {
        $probe = new DocumentTemplateVersion(['pdf_file_path' => $path]);
        $sizes = $this->geometry->pageSizes($probe);

        if ($sizes === []) {
            $disk->delete($path);

            throw new \RuntimeException('ไฟล์แบบฟอร์มในแพ็กเกจเปิดอ่านไม่ได้');
        }

        return $sizes;
    }

    /** เก็บเฉพาะคีย์ที่ตัวเรนเดอร์อ่านจริง ไฟล์ที่นำเข้าจึงเพิ่มค่าแปลกปลอมเข้าระบบไม่ได้ */
    private function safeOptions(mixed $options): ?array
    {
        if (! is_array($options)) {
            return null;
        }

        $safe = array_intersect_key($options, array_flip(DocumentFieldMapping::OPTION_KEYS));

        return $safe === [] ? null : $safe;
    }

    private function uniqueCode(string $code): string
    {
        $code = Str::limit(Str::slug($code, '_'), 100, '');
        $candidate = $code;
        $suffix = 2;

        while (DocumentTemplate::where('code', $candidate)->exists()) {
            $candidate = $code.'_'.$suffix++;
        }

        return $candidate;
    }
}
