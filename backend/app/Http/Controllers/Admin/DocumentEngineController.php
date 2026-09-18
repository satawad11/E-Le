<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document\DocumentBindingRule;
use App\Models\Document\DocumentDataSource;
use App\Models\Document\DocumentFieldDefinition;
use App\Models\Document\DocumentFieldMapping;
use App\Models\Document\DocumentGeneratedFile;
use App\Models\Document\DocumentPrintCalibration;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveType;
use App\Services\AuditLogger;
use App\Services\DefaultDocumentTemplateService;
use App\Services\DocumentEngine\DataProviderRegistry;
use App\Services\DocumentEngine\DocumentEngine;
use App\Services\DocumentEngine\EngineActivation;
use App\Services\DocumentEngine\FieldDictionarySynchronizer;
use App\Services\DocumentEngine\PrintCalibrationSheet;
use App\Services\DocumentEngine\Providers\LeaveRequestDataProvider;
use App\Services\DocumentEngine\TemplateGeometry;
use App\Services\DocumentEngine\TemplatePackage;
use App\Services\DocumentEngine\TemplatePreflight;
use App\Services\DocumentEngine\TemplateResolver;
use App\Services\DocumentEngine\ValueFormatter;
use App\Services\LeaveDocumentFields;
use App\Support\DocumentFilename;
use App\Support\UploadRules;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

/**
 * ศูนย์จัดการเอกสาร — ฝั่งผู้ดูแลระบบ
 *
 * ทุก endpoint ตรวจสิทธิ์ฝั่งเซิร์ฟเวอร์เสมอ การซ่อนเมนูฝั่งหน้าเว็บไม่ถือเป็นการควบคุมสิทธิ์
 * และไม่มีจุดใดรับ SQL จากผู้ใช้ — แหล่งข้อมูลเลือกได้เฉพาะที่ลงทะเบียนไว้ใน registry
 */
class DocumentEngineController extends Controller
{
    public function __construct(
        private readonly DocumentEngine $engine,
        private readonly DataProviderRegistry $providers,
        private readonly AuditLogger $audit,
        private readonly TemplateGeometry $geometry,
        private readonly FieldDictionarySynchronizer $fields,
        private readonly EngineActivation $activation,
    ) {}

    // ───────────────────────────────── ภาพรวม ─────────────────────────────────

    public function overview()
    {
        return response()->json([
            'templates' => DocumentTemplate::count(),
            'pdf_templates' => DocumentTemplate::where('render_mode', DocumentTemplate::RENDER_PDF_OVERLAY)->count(),
            'versions' => DocumentTemplateVersion::count(),
            'active_versions' => DocumentTemplateVersion::where('status', DocumentTemplateVersion::STATUS_ACTIVE)->count(),
            'binding_rules' => DocumentBindingRule::where('is_active', true)->count(),
            'field_definitions' => DocumentFieldDefinition::where('is_active', true)->count(),
            'data_sources' => DocumentDataSource::where('is_active', true)->count(),
            'generated_files' => DocumentGeneratedFile::count(),
            'finalized_files' => DocumentGeneratedFile::where('status', DocumentGeneratedFile::STATUS_FINALIZED)->count(),
            'engine_enabled' => $this->activation->systemEnabled(),
            'enabled_leave_types' => $this->activation->enabledLeaveTypes(),
        ]);
    }

    /**
     * เปิด/ปิดการใช้ Document Engine รายประเภทการลา
     *
     * ไม่ได้เปิดสวิตช์ระดับระบบให้ — ถ้า `.env` ปิดอยู่ รายการนี้จะถูกบันทึกไว้เฉย ๆ
     * และคำตอบจะบอกว่ายังไม่มีผลจนกว่าจะเปิดสวิตช์ระดับระบบ
     */
    public function updateEnabledEntities(Request $request)
    {
        $data = $request->validate([
            'leave_type_ids' => 'present|array',
            'leave_type_ids.*' => 'integer|exists:leave_types,id',
        ]);

        $enabled = $this->activation->setEnabledLeaveTypes($data['leave_type_ids'], $request->user());

        return response()->json([
            'message' => $this->activation->systemEnabled()
                ? 'บันทึกประเภทการลาที่ใช้แบบฟอร์ม PDF เรียบร้อยแล้ว'
                : 'บันทึกไว้แล้ว แต่ยังไม่มีผลจนกว่าจะเปิดระบบจัดทำเอกสารอัตโนมัติ (Document Engine) ระดับระบบ',
            'engine_enabled' => $this->activation->systemEnabled(),
            'enabled_leave_types' => $enabled,
        ]);
    }

    // ─────────────────────────────── แม่แบบเอกสาร ───────────────────────────────

    public function templates(Request $request)
    {
        $templates = DocumentTemplate::query()
            ->when($request->filled('module'), fn ($query) => $query->where('module', $request->string('module')))
            ->when($request->filled('document_type'), fn ($query) => $query->where('document_type', $request->string('document_type')))
            ->when($request->filled('search'), fn ($query) => $query->where(function ($q) use ($request) {
                $search = $request->string('search');
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            }))
            ->withCount('versions')
            /*
             * โหลดทั้งแถว ไม่ระบุรายชื่อคอลัมน์
             *
             * `currentVersion` ใช้ `latestOfMany()` ซึ่งสร้าง subquery join กับตารางเดียวกัน
             * การระบุคอลัมน์แบบไม่มีชื่อตารางนำหน้าจึงทำให้ `document_template_id` กำกวมและ query ล้ม
             */
            ->with('currentVersion')
            ->orderBy('module')->orderBy('code')
            ->get()
            ->map(fn (DocumentTemplate $template) => [
                'id' => $template->id,
                'code' => $template->code,
                'name' => $template->name,
                'module' => $template->module,
                'document_type' => $template->document_type,
                'render_mode' => $template->render_mode,
                'description' => $template->description,
                'is_active' => $template->is_active,
                'versions_count' => $template->versions_count,
                'active_version' => $template->currentVersion,
                'bound_entities' => $template->bindingRules()->where('is_active', true)->count(),
                'updated_at' => $template->updated_at,
            ]);

        return response()->json($templates);
    }

    public function storeTemplate(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:120|unique:document_templates,code',
            'name' => 'required|string|max:255',
            'module' => 'required|string|max:30',
            'document_type' => 'required|string|in:'.implode(',', DocumentTemplate::TYPES),
            'render_mode' => 'required|string|in:html,pdf_overlay',
            'description' => 'nullable|string|max:500',
            'paper_size' => 'nullable|string|max:20',
            'orientation' => 'nullable|string|in:portrait,landscape',
        ]);

        $template = DocumentTemplate::create($data + [
            'content_html' => '',
            'paper_size' => $data['paper_size'] ?? 'A4',
            'orientation' => $data['orientation'] ?? 'portrait',
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);

        $this->audit->log('document_template_created', 'DocumentTemplate', $template->id, after: $template->toArray());

        return response()->json(['message' => 'สร้างแม่แบบเอกสารเรียบร้อยแล้ว', 'template' => $template->fresh('currentVersion')], 201);
    }

    public function showTemplate(DocumentTemplate $template)
    {
        return response()->json([
            'template' => $template,
            'versions' => $template->versions()->orderByDesc('version')->get(),
            'binding_rules' => $template->bindingRules()->orderBy('priority')->get(),
        ]);
    }

    /*
     * ─────────────── แม่แบบชนิด HTML (CLEANUP-01 · D2) ───────────────
     *
     * เดิมแม่แบบชนิด HTML ถูกจัดการผ่าน `Admin\DocumentTemplateController`
     * ที่หน้า `/admin/document-templates` ซึ่งเป็นเมนูแยกอีกอันหนึ่ง ทั้งที่อ่านเขียน
     * ตาราง `document_templates` ชุดเดียวกับศูนย์จัดการเอกสาร ผู้ดูแลจึงต้องเดา
     * ว่าจะแก้แม่แบบที่หน้าไหน และเปลี่ยนที่หนึ่งแล้วอีกหน้าแสดงคนละอย่าง
     *
     * ย้ายมารวมไว้ที่นี่ทั้งหมด เหลือทางเข้าเดียวคือศูนย์จัดการเอกสาร
     */

    /** แก้เนื้อหาแม่แบบชนิด HTML — model hook จะออกเวอร์ชันใหม่ให้เองเมื่อเนื้อหาเปลี่ยน */
    public function updateTemplateContent(Request $request, DocumentTemplate $template)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'paper_size' => 'nullable|string|max:20',
            'orientation' => 'nullable|string|in:portrait,landscape',
            'margin_top' => 'nullable|numeric|min:0|max:100',
            'margin_bottom' => 'nullable|numeric|min:0|max:100',
            'margin_left' => 'nullable|numeric|min:0|max:100',
            'margin_right' => 'nullable|numeric|min:0|max:100',
            'content_html' => 'required|string',
            'is_active' => 'nullable|boolean',
        ]);

        $before = $template->toArray();
        $template->update($data);

        $this->audit->log('document_template_content_updated', 'DocumentTemplate', $template->id, before: $before, after: $template->fresh()->toArray());

        return response()->json([
            'message' => 'บันทึกเนื้อหาแม่แบบเรียบร้อยแล้ว',
            'template' => $template->fresh('currentVersion'),
        ]);
    }

    /** รายการตัวแปรที่ใช้ในแม่แบบชนิด HTML — คนละชุดกับ Field Dictionary ของแบบ PDF overlay */
    public function contentFields()
    {
        return response()->json(LeaveDocumentFields::catalog());
    }

    /** พรีวิวด้วยข้อมูลตัวอย่าง — ไม่แตะข้อมูลจริงและไม่ต้องมีใบลา */
    public function previewContent(Request $request, LeaveDocumentFields $fields)
    {
        $data = $request->validate([
            'content_html' => 'required|string',
            'paper_size' => 'nullable|string|max:20',
            'orientation' => 'nullable|string|max:20',
        ]);

        try {
            $html = Blade::render($data['content_html'], array_merge($fields->sample(), [
                'approverBlocks' => $this->sampleApproverBlocks(),
            ]));
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'แม่แบบมีข้อผิดพลาด: '.$exception->getMessage()], 422);
        }

        return Pdf::loadHTML($html)
            ->setPaper(strtolower($data['paper_size'] ?? 'a4'), strtolower($data['orientation'] ?? 'portrait'))
            ->stream('preview.pdf');
    }

    /** สร้าง/คืนค่าแม่แบบตั้งต้นของแบบฟอร์มราชการ */
    public function seedDefaultTemplates(Request $request, DefaultDocumentTemplateService $templates)
    {
        $overwrite = $request->boolean('overwrite');
        $templates->sync($overwrite);

        $this->audit->log('document_templates_seeded', 'DocumentTemplate', null, after: ['overwrite' => $overwrite]);

        return response()->json([
            'message' => $overwrite
                ? 'คืนค่าแบบฟอร์มตั้งต้นเรียบร้อยแล้ว (เวอร์ชันเดิมยังถูกเก็บไว้ เอกสารเก่าพิมพ์ซ้ำได้เหมือนเดิม)'
                : 'ซิงค์แม่แบบเอกสารตั้งต้นเรียบร้อยแล้ว',
        ]);
    }

    public function deleteTemplate(DocumentTemplate $template)
    {
        $before = $template->toArray();
        $template->delete();

        $this->audit->log('document_template_deleted', 'DocumentTemplate', $before['id'], before: $before);

        return response()->json(['message' => 'ลบแม่แบบเอกสารเรียบร้อยแล้ว']);
    }

    /**
     * บล็อกผู้ลงนามตัวอย่างสำหรับพรีวิว
     *
     * @return array<int, array<string, mixed>>
     */
    private function sampleApproverBlocks(): array
    {
        return collect(['HR Head' => 'หัวหน้างานบุคลากร', 'Deputy Director' => 'รองผู้อำนวยการ', 'Director' => 'ผู้อำนวยการ'])
            ->map(fn (string $position, string $role) => [
                'name' => 'ตัวอย่าง ผู้ลงนาม',
                'date' => now(),
                'signature' => null,
                'comment' => 'เห็นควรอนุญาต',
                'status' => 'Approved',
                'position' => $position,
                'role_name' => $role,
            ])
            ->values()
            ->all();
    }

    // ──────────────────────────── เวอร์ชันของแม่แบบ ────────────────────────────

    /**
     * ออกเวอร์ชันใหม่จากเวอร์ชันเดิม (Duplicate → Modify → Publish)
     *
     * เวอร์ชันที่มีเอกสารอ้างอิงแล้วห้ามแก้ในที่เดิม จึงต้องคัดลอกเป็นเวอร์ชันใหม่เสมอ
     * ตำแหน่งฟิลด์ทั้งหมดถูกคัดลอกตามมาเพื่อให้ผู้ดูแลแก้ต่อได้ทันที
     */
    public function createVersion(Request $request, DocumentTemplate $template)
    {
        $source = $request->filled('from_version_id')
            ? $template->versions()->findOrFail($request->integer('from_version_id'))
            : $template->versions()->orderByDesc('version')->first();

        $version = DB::transaction(function () use ($template, $source, $request) {
            $version = $template->versions()->create([
                'version' => ($template->versions()->max('version') ?? 0) + 1,
                'status' => DocumentTemplateVersion::STATUS_DRAFT,
                'render_mode' => $source?->render_mode ?? $template->render_mode ?? DocumentTemplate::RENDER_HTML,
                'pdf_file_path' => $source?->pdf_file_path,
                'page_count' => $source?->page_count,
                'paper_size' => $source?->paper_size ?? $template->paper_size,
                'orientation' => $source?->orientation ?? $template->orientation,
                'margin_top' => $source?->margin_top ?? $template->margin_top,
                'margin_bottom' => $source?->margin_bottom ?? $template->margin_bottom,
                'margin_left' => $source?->margin_left ?? $template->margin_left,
                'margin_right' => $source?->margin_right ?? $template->margin_right,
                'content_html' => $source?->content_html ?? $template->content_html,
                'effective_from' => now()->toDateString(),
                'is_active' => false,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($source?->fieldMappings ?? [] as $mapping) {
                $version->fieldMappings()->create($mapping->only([
                    'field_code', 'field_type', 'page', 'x', 'y', 'width', 'height',
                    'font', 'font_size', 'align', 'overflow', 'format', 'static_text', 'options', 'is_active',
                ]));
            }

            return $version;
        }, 5);

        $this->audit->log('document_version_created', 'DocumentTemplateVersion', $version->id, after: ['template' => $template->code, 'version' => $version->version]);

        return response()->json(['message' => 'สร้างฉบับร่างเวอร์ชันใหม่เรียบร้อยแล้ว', 'version' => $version->load('fieldMappings')], 201);
    }

    /** อัปโหลด PDF ต้นฉบับให้เวอร์ชัน (ได้เฉพาะฉบับร่าง) */
    public function uploadPdf(Request $request, DocumentTemplateVersion $version)
    {
        $request->validate(['file' => array_merge(['required'], UploadRules::documentTemplate())]);

        abort_unless(
            $version->status === DocumentTemplateVersion::STATUS_DRAFT,
            422,
            'เปลี่ยนไฟล์ต้นฉบับได้เฉพาะฉบับร่าง — กรุณาสร้างเวอร์ชันใหม่ก่อน',
        );

        $path = $request->file('file')->store('document-templates', 'private_uploads');

        $version->update([
            'pdf_file_path' => $path,
            'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
            'page_count' => $this->pageCount($path),
        ]);

        /*
         * บันทึกแบบเงียบ ๆ โดยตั้งใจ
         *
         * `DocumentTemplate` มี hook ที่ออกเวอร์ชันใหม่ทุกครั้งที่คุณสมบัติของแม่แบบเปลี่ยน
         * ถ้าปล่อยให้ hook ทำงานตรงนี้ การอัปโหลดไฟล์ลงฉบับร่างจะไปสร้างเวอร์ชันเปล่าเพิ่มอีกใบทุกครั้ง
         */
        $version->template->forceFill(['render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY])->saveQuietly();

        $this->audit->log('document_version_pdf_uploaded', 'DocumentTemplateVersion', $version->id, after: ['path' => $path]);

        return response()->json(['message' => 'อัปโหลดแบบฟอร์มต้นฉบับเรียบร้อยแล้ว', 'version' => $version->fresh()]);
    }

    /** ไฟล์ PDF ต้นฉบับ ใช้แสดงเป็นพื้นหลังในหน้าออกแบบ */
    public function pdfFile(DocumentTemplateVersion $version)
    {
        abort_if(blank($version->pdf_file_path), 404, 'เวอร์ชันนี้ยังไม่มีไฟล์ต้นฉบับ');

        $disk = Storage::disk('private_uploads');
        abort_unless($disk->exists($version->pdf_file_path), 404, 'ไม่พบไฟล์ต้นฉบับในระบบ');

        return response($disk->get($version->pdf_file_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="template-'.$version->id.'.pdf"',
        ]);
    }

    public function publishVersion(Request $request, DocumentTemplateVersion $version)
    {
        abort_if($version->status === DocumentTemplateVersion::STATUS_ACTIVE, 422, 'เวอร์ชันนี้เผยแพร่อยู่แล้ว');

        /*
         * ตรวจเต็มชุดก่อนเผยแพร่ (DESIGNER-04)
         *
         * ตำแหน่งฟิลด์อาจมาจากการนำเข้าหรือคัดลอกเวอร์ชัน ไม่ได้ผ่านหน้าออกแบบทุกครั้ง
         * และความผิดพลาดที่เหลืออยู่จะไปโผล่ตอนผู้ใช้พิมพ์เอกสารจริง
         */
        $result = app(TemplatePreflight::class)->check($version);

        if ($result['errors'] !== []) {
            return response()->json([
                'message' => 'เผยแพร่ไม่ได้เพราะแบบฟอร์มยังมีข้อผิดพลาด',
                'errors' => ['mappings' => $result['errors']],
                'warnings' => $result['warnings'],
            ], 422);
        }

        DB::transaction(function () use ($version, $request) {
            // เวอร์ชันที่ใช้อยู่เดิมถูกปลดเป็น RETIRED (ไม่ลบ) เอกสารเก่าที่อ้างอยู่จึงยังพิมพ์ได้
            DocumentTemplateVersion::where('document_template_id', $version->document_template_id)
                ->where('id', '!=', $version->id)
                ->where('status', DocumentTemplateVersion::STATUS_ACTIVE)
                ->update([
                    'status' => DocumentTemplateVersion::STATUS_RETIRED,
                    'is_active' => false,
                    'effective_to' => now()->subDay()->toDateString(),
                ]);

            $version->update([
                'status' => DocumentTemplateVersion::STATUS_ACTIVE,
                'is_active' => true,
                'effective_from' => now()->toDateString(),
                'effective_to' => null,
                'published_by' => $request->user()?->id,
                'published_at' => now(),
            ]);

            // แม่แบบสะท้อนวิธีเรนเดอร์ของเวอร์ชันที่ใช้งานอยู่ (saveQuietly กัน hook ออกเวอร์ชันเปล่า)
            $version->template->forceFill(['render_mode' => $version->render_mode])->saveQuietly();
        }, 5);

        $this->audit->log('document_version_published', 'DocumentTemplateVersion', $version->id, after: ['version' => $version->version]);

        return response()->json(['message' => 'เผยแพร่เวอร์ชันเรียบร้อยแล้ว', 'version' => $version->fresh()]);
    }

    /**
     * ลบฉบับร่างที่ไม่ได้ใช้
     *
     * ลบได้เฉพาะ DRAFT ที่ไม่มีเอกสารใดอ้างถึงเท่านั้น — เวอร์ชันที่เผยแพร่แล้วหรือมีใบลา
     * อ้างอยู่ต้องคงไว้ตลอดไป (ใช้ "ปลดการใช้งาน" แทน) ตามกฎเอกสารย้อนหลังของระบบ
     */
    public function deleteVersion(DocumentTemplateVersion $version)
    {
        abort_unless(
            $version->status === DocumentTemplateVersion::STATUS_DRAFT,
            422,
            'ลบได้เฉพาะฉบับร่างเท่านั้น — เวอร์ชันที่เผยแพร่แล้วให้ใช้การปลดการใช้งานแทน',
        );

        abort_if($version->isReferenced(), 422, 'ลบไม่ได้เพราะมีเอกสารอ้างอิงเวอร์ชันนี้อยู่');

        $templateId = $version->document_template_id;
        $number = $version->version;

        DB::transaction(function () use ($version) {
            $version->fieldMappings()->delete();
            $version->delete();
        }, 5);

        $this->audit->log('document_version_deleted', 'DocumentTemplateVersion', $version->id, after: [
            'template_id' => $templateId,
            'version' => $number,
        ]);

        return response()->json(['message' => 'ลบฉบับร่างเรียบร้อยแล้ว']);
    }

    public function retireVersion(DocumentTemplateVersion $version)
    {
        $version->update([
            'status' => DocumentTemplateVersion::STATUS_RETIRED,
            'is_active' => false,
            'effective_to' => now()->toDateString(),
        ]);

        $this->audit->log('document_version_retired', 'DocumentTemplateVersion', $version->id);

        return response()->json(['message' => 'ปลดเวอร์ชันออกจากการใช้งานแล้ว (เอกสารเก่ายังพิมพ์ได้เหมือนเดิม)']);
    }

    // ───────────────────────────── ตำแหน่งฟิลด์ ─────────────────────────────

    public function mappings(DocumentTemplateVersion $version)
    {
        return response()->json([
            'version' => $version,
            'mappings' => $version->fieldMappings()->orderBy('page')->orderBy('id')->get(),
            'fields' => DocumentFieldDefinition::where('is_active', true)->orderBy('group_th')->orderBy('code')->get(),
            // หน้าออกแบบต้องเลือกได้เฉพาะรูปแบบ/ชนิดที่ตัวเรนเดอร์ทำได้จริง จึงส่งรายการจากฝั่งเซิร์ฟเวอร์
            'formats' => ValueFormatter::FORMATS,
            'field_types' => DocumentFieldMapping::RENDERABLE_TYPES,
        ]);
    }

    /** บันทึกตำแหน่งฟิลด์ทั้งชุดของเวอร์ชัน (ได้เฉพาะฉบับร่าง) */
    public function saveMappings(Request $request, DocumentTemplateVersion $version)
    {
        $data = $request->validate([
            'mappings' => 'present|array',
            'mappings.*.field_code' => 'required|string|max:120',
            'mappings.*.field_type' => 'required|string|in:'.implode(',', DocumentFieldMapping::RENDERABLE_TYPES),
            'mappings.*.page' => 'required|integer|min:1|max:50',
            'mappings.*.x' => 'required|numeric|min:0|max:5000',
            'mappings.*.y' => 'required|numeric|min:0|max:5000',
            'mappings.*.width' => 'required|numeric|min:1|max:5000',
            'mappings.*.height' => 'required|numeric|min:1|max:5000',
            'mappings.*.font' => 'nullable|string|max:60',
            'mappings.*.font_size' => 'nullable|numeric|min:4|max:72',
            'mappings.*.align' => 'nullable|string|in:left,center,right',
            'mappings.*.overflow' => 'nullable|string|in:shrink,wrap,clip',
            'mappings.*.format' => 'nullable|string|in:'.implode(',', array_keys(ValueFormatter::FORMATS)),
            'mappings.*.static_text' => 'nullable|string|max:500',
            /*
             * `options` รับเฉพาะคีย์ที่ตัวเรนเดอร์อ่านจริง
             *
             * เดิมเป็น array อิสระ ผู้เรียกจึงส่งคีย์อะไรมาก็ได้และไม่มีผลใด ๆ กับเอกสาร
             * ทำให้แยกไม่ออกว่าค่าที่ตั้งไว้ "ไม่ทำงาน" หรือ "ยังไม่รองรับ"
             */
            'mappings.*.options' => 'nullable|array:'.implode(',', DocumentFieldMapping::OPTION_KEYS),
            'mappings.*.options.mark' => 'nullable|string|max:4',
            'mappings.*.options.checked_values' => 'nullable|array|max:20',
            'mappings.*.options.checked_values.*' => 'string|max:120',
            'mappings.*.options.underline' => 'nullable|boolean',
            'mappings.*.options.letter_spacing' => 'nullable|numeric|min:0|max:20',
            'mappings.*.options.rotate' => 'nullable|integer|in:0,90,180,270',
            'mappings.*.options.visible_when' => 'nullable|array:field,operator,value',
            'mappings.*.options.visible_when.field' => 'required_with:mappings.*.options.visible_when|string|max:120',
            'mappings.*.options.visible_when.operator' => 'required_with:mappings.*.options.visible_when|string|in:'.implode(',', DocumentFieldMapping::CONDITION_OPERATORS),
            'mappings.*.options.visible_when.value' => 'nullable|string|max:255',
        ]);

        abort_unless(
            $version->status === DocumentTemplateVersion::STATUS_DRAFT,
            422,
            'แก้ตำแหน่งฟิลด์ได้เฉพาะฉบับร่าง — เวอร์ชันที่เผยแพร่แล้วต้องสร้างเวอร์ชันใหม่ก่อน',
        );

        /*
         * ฟิลด์ต้องอยู่ในหน้ากระดาษจริงของไฟล์ต้นฉบับ
         *
         * ตรวจที่ฝั่งเซิร์ฟเวอร์เสมอ เพราะหน้าจอเป็นเพียงตัวช่วยวาง ไม่ใช่ผู้รับรอง
         * ฟิลด์ที่เลยขอบกระดาษจะถูกวาดหายไปเงียบ ๆ ตอนพิมพ์ ซึ่งเห็นไม่ได้จนกว่าจะพิมพ์จริง
         */
        $problems = $this->geometry->problems($version, $data['mappings']);

        if ($problems !== []) {
            return response()->json([
                'message' => 'บันทึกไม่ได้เพราะมีฟิลด์อยู่นอกหน้ากระดาษ',
                'errors' => ['mappings' => $problems],
            ], 422);
        }

        DB::transaction(function () use ($version, $data) {
            $version->fieldMappings()->delete();

            foreach ($data['mappings'] as $mapping) {
                $version->fieldMappings()->create([
                    'field_code' => $mapping['field_code'],
                    'field_type' => $mapping['field_type'],
                    'page' => $mapping['page'],
                    'x' => $mapping['x'],
                    'y' => $mapping['y'],
                    'width' => $mapping['width'],
                    'height' => $mapping['height'],
                    'font' => $mapping['font'] ?? null,
                    'font_size' => $mapping['font_size'] ?? 14,
                    'align' => $mapping['align'] ?? 'left',
                    'overflow' => $mapping['overflow'] ?? 'shrink',
                    'format' => $mapping['format'] ?? null,
                    'static_text' => $mapping['static_text'] ?? null,
                    'options' => $mapping['options'] ?? null,
                    'is_active' => true,
                ]);
            }
        }, 5);

        $this->audit->log('document_field_mapping_changed', 'DocumentTemplateVersion', $version->id, after: ['count' => count($data['mappings'])]);

        return response()->json([
            'message' => 'บันทึกตำแหน่งฟิลด์เรียบร้อยแล้ว',
            'mappings' => $version->fieldMappings()->orderBy('page')->get(),
        ]);
    }

    /**
     * ขนาดจริงของแต่ละหน้าในแบบฟอร์มต้นฉบับ
     *
     * หน้าออกแบบใช้ค่านี้เป็นระบบพิกัดแทนการเดาว่าเป็น A4 เสมอ จึงวางฟิลด์บนแบบฟอร์ม
     * ขนาดอื่น (F4/Legal/แนวนอน/ไฟล์สแกน) แล้วพิมพ์ออกมาตรงตำแหน่งเดียวกับที่เห็นบนจอ
     */
    public function versionGeometry(DocumentTemplateVersion $version)
    {
        $sizes = $this->geometry->pageSizes($version);

        return response()->json([
            'page_count' => count($sizes),
            'pages' => array_map(
                fn (array $size, int $index) => ['page' => $index + 1] + $size,
                $sizes,
                array_keys($sizes),
            ),
        ]);
    }

    /**
     * พรีวิวเอกสารสามโหมด
     *
     *   `sample` (ค่าเริ่มต้น) — ข้อความไทยยาวตามกรณีจริง ใช้ตรวจว่าช่องพอหรือไม่
     *   `blank`  — วาดเฉพาะกรอบของช่อง ใช้เทียบกับเส้นบนแบบฟอร์มต้นฉบับ
     *   `leave`  — ใบลาจริงหนึ่งฉบับ (อ่านอย่างเดียว) ต้องมีสิทธิ์เปิดใบนั้นด้วย และถูกบันทึก audit
     */
    public function previewVersion(Request $request, DocumentTemplateVersion $version)
    {
        abort_unless($version->isPdfOverlay(), 422, 'พรีวิวแบบวางฟิลด์ใช้ได้กับแม่แบบชนิด PDF เท่านั้น');

        $data = $request->validate([
            'mode' => 'nullable|string|in:sample,blank,leave',
            'leave_id' => 'nullable|integer|exists:leaves,id',
        ]);

        $mode = $data['mode'] ?? 'sample';

        if ($mode !== 'leave') {
            return response($this->engine->preview($version, $mode), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="preview.pdf"',
            ]);
        }

        abort_if(blank($data['leave_id'] ?? null), 422, 'เลือกใบลาที่จะใช้เป็นตัวอย่างก่อน');

        $leave = Leave::findOrFail($data['leave_id']);

        /*
         * สิทธิ์จัดการแม่แบบไม่ใช่สิทธิ์เปิดใบลาของผู้อื่น
         *
         * การพรีวิวด้วยข้อมูลจริงคือการอ่านเอกสารของบุคคล จึงต้องผ่าน policy เดียวกับ
         * การเปิดใบลานั้นตามปกติ และถูกบันทึกว่าใครเปิดของใครเมื่อใด
         */
        $this->authorize('view', $leave);

        $content = $this->engine->previewWithData($version, LeaveRequestDataProvider::CODE, [
            'leave' => $leave,
            'stage' => LeaveApproval::STAGE_REQUEST,
        ]);

        $this->audit->log('document_preview_with_real_data', 'Leave', $leave->id, after: [
            'version_id' => $version->id,
        ]);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preview-leave-'.$leave->id.'.pdf"',
        ]);
    }

    /** ผลตรวจก่อนเผยแพร่ — ให้หน้าออกแบบแสดงก่อนกดปุ่ม ไม่ใช่ให้รู้ตอนถูกปฏิเสธ */
    public function preflight(DocumentTemplateVersion $version, TemplatePreflight $preflight)
    {
        return response()->json($preflight->check($version));
    }

    /** ใบสอบเทียบการพิมพ์ — พิมพ์ออกกระดาษจริงแล้ววัดด้วยไม้บรรทัด */
    public function calibrationSheet(DocumentTemplateVersion $version, PrintCalibrationSheet $sheet)
    {
        return response($sheet->render($version), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="calibration-sheet.pdf"',
        ]);
    }

    /** คำนวณค่าชดเชยจากระยะที่วัดได้ (ยังไม่บันทึก — ผู้ดูแลตรวจก่อนกดบันทึกที่หน้าค่าชดเชย) */
    public function solveCalibration(Request $request, PrintCalibrationSheet $sheet)
    {
        $data = $request->validate([
            'measured_a_x_mm' => 'required|numeric|min:0|max:1000',
            'measured_a_y_mm' => 'required|numeric|min:0|max:1000',
            'measured_ab_mm' => 'required|numeric|min:1|max:1000',
            'measured_ac_mm' => 'required|numeric|min:1|max:1000',
        ]);

        return response()->json([
            'calibration' => $sheet->solve(
                (float) $data['measured_a_x_mm'],
                (float) $data['measured_a_y_mm'],
                (float) $data['measured_ab_mm'],
                (float) $data['measured_ac_mm'],
            ),
        ]);
    }

    /**
     * เทียบตำแหน่งฟิลด์ระหว่างสองเวอร์ชัน
     *
     * ก่อนเผยแพร่ ผู้ดูแลต้องตอบได้ว่า "เวอร์ชันใหม่ต่างจากของเดิมตรงไหน"
     * ซึ่งเดิมต้องเปิดสองหน้าจอเทียบด้วยตา
     */
    public function diffVersion(Request $request, DocumentTemplateVersion $version)
    {
        $data = $request->validate(['against' => 'required|integer|exists:document_template_versions,id']);

        $other = DocumentTemplateVersion::findOrFail($data['against']);

        abort_unless(
            $other->document_template_id === $version->document_template_id,
            422,
            'เทียบได้เฉพาะเวอร์ชันของแม่แบบเดียวกัน',
        );

        $key = fn ($mapping) => $mapping->field_code.'#'.$mapping->page;
        $current = $version->fieldMappings->keyBy($key);
        $previous = $other->fieldMappings->keyBy($key);

        $changed = [];

        foreach ($current as $id => $mapping) {
            $before = $previous->get($id);

            if ($before === null) {
                continue;
            }

            $differences = $this->differences($before, $mapping);

            if ($differences !== []) {
                $changed[] = ['field_code' => $mapping->field_code, 'page' => (int) $mapping->page, 'changes' => $differences];
            }
        }

        return response()->json([
            'from' => ['id' => $other->id, 'version' => $other->version, 'status' => $other->status],
            'to' => ['id' => $version->id, 'version' => $version->version, 'status' => $version->status],
            'added' => $current->keys()->diff($previous->keys())->values(),
            'removed' => $previous->keys()->diff($current->keys())->values(),
            'changed' => $changed,
        ]);
    }

    /**
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function differences(DocumentFieldMapping $before, DocumentFieldMapping $after): array
    {
        $fields = ['x', 'y', 'width', 'height', 'font', 'font_size', 'align', 'overflow', 'format', 'static_text', 'field_type'];
        $differences = [];

        foreach ($fields as $field) {
            if ((string) $before->{$field} !== (string) $after->{$field}) {
                $differences[$field] = ['from' => $before->{$field}, 'to' => $after->{$field}];
            }
        }

        if (json_encode($before->options) !== json_encode($after->options)) {
            $differences['options'] = ['from' => $before->options, 'to' => $after->options];
        }

        return $differences;
    }

    /** ส่งออกแบบฟอร์มหนึ่งเวอร์ชันเป็นไฟล์เดียว (ZIP: manifest.json + form.pdf) */
    public function exportPackage(DocumentTemplateVersion $version, TemplatePackage $package)
    {
        $path = $package->export($version);

        $this->audit->log('document_template_exported', 'DocumentTemplateVersion', $version->id, after: [
            'template' => $version->template->code,
            'version' => $version->version,
        ]);

        return response()->download($path, $version->template->code.'-v'.$version->version.'.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /** นำเข้าแพ็กเกจเป็นแม่แบบใหม่พร้อมฉบับร่าง — ไม่ทับแม่แบบที่มีอยู่ */
    public function importPackage(Request $request, TemplatePackage $package)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimetypes:application/zip,application/x-zip-compressed,multipart/x-zip'],
        ]);

        try {
            $version = $package->import($request->file('file')->getRealPath(), $request->user());
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->audit->log('document_template_imported', 'DocumentTemplateVersion', $version->id, after: [
            'template_id' => $version->document_template_id,
        ]);

        return response()->json([
            'message' => 'นำเข้าแบบฟอร์มเป็นฉบับร่างเรียบร้อยแล้ว — ตรวจตำแหน่งฟิลด์แล้วจึงเผยแพร่',
            'version' => $version,
            'template_id' => $version->document_template_id,
        ], 201);
    }

    // ─────────────────────────── Field Dictionary / แหล่งข้อมูล ───────────────────────────

    public function fieldDictionary(Request $request)
    {
        /*
         * ซิงก์ก่อนเสมอ เช่นเดียวกับรายการแหล่งข้อมูล
         *
         * ช่องเฉพาะของประเภทการลา ขั้นอนุมัติ และประเภทการลาที่เพิ่มภายหลัง ทำให้รายการ
         * ตัวแปรเปลี่ยนได้ตลอดเวลา ผู้ดูแลจึงต้องเห็นสิ่งที่ระบบมีค่าให้จริง ณ ตอนที่เปิดหน้า
         */
        $this->fields->sync();

        return response()->json(
            DocumentFieldDefinition::query()
                ->when($request->filled('module'), fn ($query) => $query->where('module', $request->string('module')))
                ->where('is_active', true)
                ->orderBy('group_th')->orderBy('code')
                ->get(),
        );
    }

    /**
     * แก้ป้ายชื่อ/กลุ่ม/รูปแบบ/การเปิดใช้ของตัวแปรในพจนานุกรม
     *
     * แก้ได้เฉพาะสิ่งที่เป็น "การนำเสนอ" — รหัสฟิลด์ ชนิดข้อมูล และแหล่งข้อมูลเป็นข้อเท็จจริง
     * ของระบบที่ `FieldDictionarySynchronizer` กำหนด ถ้าให้แก้จากหน้าจอจะเกิดฟิลด์ที่ไม่มีค่าจริง
     * มารองรับ และผู้ดูแลจะวางช่องที่พิมพ์ออกมาว่างเปล่าโดยไม่รู้ตัว
     */
    public function updateField(Request $request, DocumentFieldDefinition $field)
    {
        $data = $request->validate([
            'label_th' => 'required|string|max:255',
            'group_th' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:255',
            'format' => 'nullable|string|in:'.implode(',', array_keys(ValueFormatter::FORMATS)),
            'is_active' => 'nullable|boolean',
        ]);

        $before = $field->toArray();
        $field->update($data);

        $this->audit->log('document_field_updated', 'DocumentFieldDefinition', $field->id, before: $before, after: $field->fresh()->toArray());

        return response()->json(['message' => 'บันทึกตัวแปรเรียบร้อยแล้ว', 'field' => $field->fresh()]);
    }

    /**
     * ปิดการใช้งานตัวแปร (ไม่ลบทิ้ง)
     *
     * แบบฟอร์มเวอร์ชันเก่าที่วางฟิลด์นี้ไว้ยังต้องพิมพ์ได้เหมือนเดิม การลบแถวออกจะทำให้
     * ตำแหน่งฟิลด์เดิมกลายเป็นรหัสลอย ๆ ที่ไม่มีใครอธิบายได้ว่าเคยหมายถึงอะไร
     */
    public function deactivateField(DocumentFieldDefinition $field)
    {
        $field->update(['is_active' => false]);

        $this->audit->log('document_field_deactivated', 'DocumentFieldDefinition', $field->id, after: ['code' => $field->code]);

        return response()->json(['message' => 'ปิดการใช้งานตัวแปรแล้ว (แบบฟอร์มเดิมที่ใช้อยู่ยังพิมพ์ได้เหมือนเดิม)']);
    }

    public function dataSources()
    {
        // ซิงก์รายการจาก registry ก่อนเสมอ — สิ่งที่ผู้ดูแลเห็นจึงตรงกับ provider ที่ระบบมีจริง
        $this->providers->sync();

        return response()->json(DocumentDataSource::where('is_active', true)->orderBy('module')->orderBy('code')->get());
    }

    // ───────────────────────────── กติกาผูกแม่แบบ ─────────────────────────────

    public function bindings(Request $request)
    {
        $rules = DocumentBindingRule::query()
            ->when($request->filled('module'), fn ($query) => $query->where('module', $request->string('module')))
            ->with('template:id,code,name,module,document_type,render_mode')
            ->orderBy('module')->orderBy('entity_type')->orderBy('priority')
            ->get();

        $leaveTypes = LeaveType::orderBy('id')->get(['id', 'name']);
        $boundTypeIds = $rules->where('module', 'LEAVE')->where('entity_type', 'LEAVE_TYPE')->pluck('entity_id')->filter()->all();
        $hasCatchAll = $rules->where('module', 'LEAVE')->where('entity_type', 'LEAVE_TYPE')->whereNull('entity_id')->isNotEmpty();

        return response()->json([
            'rules' => $rules,
            // ตัวเลือกของ entity ที่ผูกได้ในตอนนี้ — ประเภทการลาของโมดูล E-Leave
            'entities' => [
                'LEAVE' => [
                    'LEAVE_TYPE' => $leaveTypes,
                    'LEAVE_CANCELLATION' => $leaveTypes,
                ],
            ],
            /*
             * ประเภทการลาที่ยังไม่มีแบบฟอร์ม — ผู้ดูแลจะได้รู้ว่ายังต้องอัปโหลดแบบฟอร์มของประเภทใดบ้าง
             * ประเภทเหล่านี้ยังพิมพ์เอกสารได้ตามปกติด้วยเส้นทางเดิม เพียงแต่ยังไม่มีแบบฟอร์มเฉพาะของตน
             */
            'unbound_leave_types' => $hasCatchAll
                ? []
                : $leaveTypes->whereNotIn('id', $boundTypeIds)->values(),
        ]);
    }

    /**
     * สถานะแบบฟอร์มของแต่ละประเภทการลา — ผูกแม่แบบใด เวอร์ชันใด พร้อมใช้หรือยัง
     *
     * หน้า "ประเภทการลา" ใช้ค่านี้บอกผู้ดูแลว่าประเภทไหนยังไม่มีแบบฟอร์ม PDF ที่เผยแพร่แล้ว
     * ซึ่งเดิมต้องเดาเอง หรือไล่เปิดศูนย์จัดการเอกสารทีละแม่แบบ
     */
    public function leaveTypeForms()
    {
        $resolver = app(TemplateResolver::class);
        $enabled = $this->activation->enabledLeaveTypes();
        $all = in_array('*', $enabled, true);

        $rows = LeaveType::orderBy('id')->get(['id', 'name'])->map(function (LeaveType $type) use ($resolver, $enabled, $all) {
            $template = $resolver->resolveTemplate('LEAVE', 'LEAVE_TYPE', $type->id);
            $version = $template === null ? null : $resolver->activeVersion($template);

            return [
                'leave_type_id' => $type->id,
                'leave_type_name' => $type->name,
                'template_id' => $template?->id,
                'template_code' => $template?->code,
                'template_name' => $template?->name,
                'render_mode' => $version?->render_mode ?? $template?->render_mode,
                'version' => $version?->version,
                'version_id' => $version?->id,
                'has_source_file' => filled($version?->pdf_file_path),
                'field_count' => $version?->fieldMappings()->count() ?? 0,
                'engine_enabled' => $all || in_array((string) $type->id, $enabled, true),
            ];
        });

        return response()->json([
            'engine_enabled' => $this->activation->systemEnabled(),
            'rows' => $rows,
        ]);
    }

    public function saveBinding(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer|exists:document_binding_rules,id',
            'module' => 'required|string|max:30',
            'entity_type' => 'required|string|max:60',
            'entity_id' => 'nullable|integer',
            'document_template_id' => 'required|integer|exists:document_templates,id',
            'priority' => 'nullable|integer|min:1|max:9999',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'conditions' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $rule = DocumentBindingRule::updateOrCreate(
            ['id' => $data['id'] ?? null],
            $data + ['priority' => $data['priority'] ?? 100, 'is_active' => $data['is_active'] ?? true, 'created_by' => $request->user()?->id],
        );

        $this->audit->log('document_binding_changed', 'DocumentBindingRule', $rule->id, after: $rule->toArray());

        return response()->json(['message' => 'บันทึกกติกาการผูกแบบฟอร์มเรียบร้อยแล้ว', 'rule' => $rule->load('template')]);
    }

    public function deleteBinding(DocumentBindingRule $rule)
    {
        $rule->delete();
        $this->audit->log('document_binding_deleted', 'DocumentBindingRule', $rule->id);

        return response()->json(['message' => 'ลบกติกาเรียบร้อยแล้ว']);
    }

    // ───────────────────────────── เอกสารที่สร้างแล้ว ─────────────────────────────

    public function generatedFiles(Request $request)
    {
        $files = DocumentGeneratedFile::query()
            ->when($request->filled('module'), fn ($query) => $query->where('module', $request->string('module')))
            ->when($request->filled('source_type'), fn ($query) => $query->where('source_type', $request->string('source_type')))
            ->with(['template:id,code,name', 'version:id,version', 'generatedBy:id,name'])
            ->orderByDesc('id')
            ->paginate(30);

        return response()->json($files);
    }

    public function downloadGenerated(DocumentGeneratedFile $file)
    {
        $disk = Storage::disk('private_uploads');
        abort_unless($disk->exists($file->file_path), 404, 'ไม่พบไฟล์เอกสารในระบบ');

        $this->audit->log('document_downloaded', 'DocumentGeneratedFile', $file->id, after: ['revision' => $file->revision]);

        /*
         * ชื่อไฟล์บอกว่าเป็นเอกสารอะไร ของรายการไหน ฉบับที่เท่าไร
         * เดิมได้ชื่อเป็นรหัสภายในล้วน (`leave-12-r2.pdf`) ซึ่งผู้ดูแลที่ดาวน์โหลดหลายไฟล์
         * มาตรวจสอบพร้อมกันแยกไม่ออกว่าไฟล์ไหนคือแบบฟอร์มใด
         */
        $filename = DocumentFilename::make([
            $file->template?->name ?: $file->source_type,
            'เลขที่'.$file->source_id,
            'ฉบับที่'.$file->revision,
        ], 'pdf');

        return response($disk->get($file->file_path), 200, [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => DocumentFilename::disposition($filename),
        ]);
    }

    // ───────────────────────────── ค่าชดเชยการพิมพ์ ─────────────────────────────

    public function calibrations()
    {
        return response()->json(
            DocumentPrintCalibration::query()->orderBy('scope')->orderByDesc('id')->get(),
        );
    }

    public function saveCalibration(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer|exists:document_print_calibrations,id',
            'scope' => 'required|string|in:GLOBAL,TEMPLATE,USER',
            'document_template_id' => 'nullable|integer|exists:document_templates,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'printer_profile' => 'nullable|string|max:120',
            'x_offset' => 'required|numeric|between:-100,100',
            'y_offset' => 'required|numeric|between:-100,100',
            'scale' => 'required|numeric|between:0.5,1.5',
            'is_active' => 'nullable|boolean',
        ]);

        $calibration = DocumentPrintCalibration::updateOrCreate(
            ['id' => $data['id'] ?? null],
            $data + ['is_active' => $data['is_active'] ?? true],
        );

        $this->audit->log('document_calibration_changed', 'DocumentPrintCalibration', $calibration->id, after: $calibration->toArray());

        return response()->json(['message' => 'บันทึกค่าชดเชยการพิมพ์เรียบร้อยแล้ว', 'calibration' => $calibration]);
    }

    private function pageCount(string $path): int
    {
        try {
            $pdf = new Fpdi;

            return $pdf->setSourceFile(Storage::disk('private_uploads')->path($path));
        } catch (\Throwable $exception) {
            report($exception);

            return 1;
        }
    }
}
