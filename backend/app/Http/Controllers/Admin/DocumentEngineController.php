<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document\DocumentBindingRule;
use App\Models\Document\DocumentDataSource;
use App\Models\Document\DocumentFieldDefinition;
use App\Models\Document\DocumentGeneratedFile;
use App\Models\Document\DocumentPrintCalibration;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\LeaveType;
use App\Services\AuditLogger;
use App\Services\DocumentEngine\DataProviderRegistry;
use App\Services\DocumentEngine\DocumentEngine;
use Illuminate\Http\Request;
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
            'engine_enabled' => (bool) config('document_engine.enabled'),
            'enabled_leave_types' => array_values((array) config('document_engine.entities.leave_types', [])),
        ]);
    }

    // ─────────────────────────────── แม่แบบเอกสาร ───────────────────────────────

    public function templates(Request $request)
    {
        $templates = DocumentTemplate::query()
            ->when($request->filled('module'), fn ($query) => $query->where('module', $request->string('module')))
            ->when($request->filled('document_type'), fn ($query) => $query->where('document_type', $request->string('document_type')))
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
        $request->validate(['file' => 'required|file|mimetypes:application/pdf|max:20480']);

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

        $version->template->update(['render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY]);

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
        }, 5);

        $this->audit->log('document_version_published', 'DocumentTemplateVersion', $version->id, after: ['version' => $version->version]);

        return response()->json(['message' => 'เผยแพร่เวอร์ชันเรียบร้อยแล้ว', 'version' => $version->fresh()]);
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
        ]);
    }

    /** บันทึกตำแหน่งฟิลด์ทั้งชุดของเวอร์ชัน (ได้เฉพาะฉบับร่าง) */
    public function saveMappings(Request $request, DocumentTemplateVersion $version)
    {
        $data = $request->validate([
            'mappings' => 'present|array',
            'mappings.*.field_code' => 'required|string|max:120',
            'mappings.*.field_type' => 'required|string|in:'.implode(',', DocumentFieldDefinition::TYPES),
            'mappings.*.page' => 'required|integer|min:1|max:50',
            'mappings.*.x' => 'required|numeric|min:0|max:5000',
            'mappings.*.y' => 'required|numeric|min:0|max:5000',
            'mappings.*.width' => 'required|numeric|min:1|max:5000',
            'mappings.*.height' => 'required|numeric|min:1|max:5000',
            'mappings.*.font' => 'nullable|string|max:60',
            'mappings.*.font_size' => 'nullable|numeric|min:4|max:72',
            'mappings.*.align' => 'nullable|string|in:left,center,right',
            'mappings.*.overflow' => 'nullable|string|in:shrink,wrap,clip',
            'mappings.*.format' => 'nullable|string|max:60',
            'mappings.*.static_text' => 'nullable|string|max:500',
            'mappings.*.options' => 'nullable|array',
        ]);

        abort_unless(
            $version->status === DocumentTemplateVersion::STATUS_DRAFT,
            422,
            'แก้ตำแหน่งฟิลด์ได้เฉพาะฉบับร่าง — เวอร์ชันที่เผยแพร่แล้วต้องสร้างเวอร์ชันใหม่ก่อน',
        );

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

    /** พรีวิวเอกสารด้วยข้อมูลตัวอย่าง — ไม่แตะข้อมูลจริงของผู้ใด */
    public function previewVersion(DocumentTemplateVersion $version)
    {
        abort_unless($version->isPdfOverlay(), 422, 'พรีวิวแบบวางฟิลด์ใช้ได้กับแม่แบบชนิด PDF เท่านั้น');

        return response($this->engine->preview($version), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preview.pdf"',
        ]);
    }

    // ─────────────────────────── Field Dictionary / แหล่งข้อมูล ───────────────────────────

    public function fieldDictionary(Request $request)
    {
        return response()->json(
            DocumentFieldDefinition::query()
                ->when($request->filled('module'), fn ($query) => $query->where('module', $request->string('module')))
                ->where('is_active', true)
                ->orderBy('group_th')->orderBy('code')
                ->get(),
        );
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

        return response()->json([
            'rules' => $rules,
            // ตัวเลือกของ entity ที่ผูกได้ในตอนนี้ — ประเภทการลาของโมดูล E-Leave
            'entities' => [
                'LEAVE' => [
                    'LEAVE_TYPE' => LeaveType::orderBy('name')->get(['id', 'name']),
                    'LEAVE_CANCELLATION' => LeaveType::orderBy('name')->get(['id', 'name']),
                ],
            ],
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

        return response($disk->get($file->file_path), 200, [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => 'attachment; filename="'.$file->source_type.'-'.$file->source_id.'-r'.$file->revision.'.pdf"',
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
