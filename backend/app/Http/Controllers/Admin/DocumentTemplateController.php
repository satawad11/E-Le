<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Services\DefaultDocumentTemplateService;
use App\Services\LeaveDocumentFields;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;

class DocumentTemplateController extends Controller
{
    /**
     * List all document templates.
     */
    public function index(Request $request)
    {
        $query = DocumentTemplate::query();

        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%")
                ->orWhere('code', 'like', "%{$request->search}%");
        }

        return response()->json($query->orderBy('module')->orderBy('id')->get());
    }

    /**
     * Store a new custom document template.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:100|unique:document_templates,code',
            'name' => 'required|string|max:255',
            'module' => 'required|string|max:50',
            'paper_size' => 'nullable|string|max:20',
            'orientation' => 'nullable|string|max:20',
            'margin_top' => 'nullable|numeric',
            'margin_bottom' => 'nullable|numeric',
            'margin_left' => 'nullable|numeric',
            'margin_right' => 'nullable|numeric',
            'content_html' => 'required|string',
            'is_active' => 'boolean',
        ]);

        $template = DocumentTemplate::create($data);

        return response()->json([
            'message' => 'สร้างแม่แบบเอกสารใหม่เรียบร้อยแล้ว',
            'template' => $template,
        ], 201);
    }

    /**
     * Show document template detail.
     */
    public function show(DocumentTemplate $template)
    {
        return response()->json($template);
    }

    /**
     * Update an existing document template.
     */
    public function update(Request $request, DocumentTemplate $template)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'module' => 'required|string|max:50',
            'paper_size' => 'nullable|string|max:20',
            'orientation' => 'nullable|string|max:20',
            'margin_top' => 'nullable|numeric',
            'margin_bottom' => 'nullable|numeric',
            'margin_left' => 'nullable|numeric',
            'margin_right' => 'nullable|numeric',
            'content_html' => 'required|string',
            'is_active' => 'boolean',
        ]);

        $template->update($data);

        return response()->json([
            'message' => 'ปรับปรุงแม่แบบเอกสารเรียบร้อยแล้ว',
            'template' => $template,
        ]);
    }

    /**
     * Delete a document template.
     */
    public function destroy(DocumentTemplate $template)
    {
        $template->delete();

        return response()->json(['message' => 'ลบแม่แบบเอกสารเรียบร้อยแล้ว']);
    }

    /**
     * Seed default document templates if empty.
     */
    public function seedDefaults(Request $request, DefaultDocumentTemplateService $templates)
    {
        $overwrite = $request->boolean('overwrite');

        $templates->sync($overwrite);

        return response()->json([
            'message' => $overwrite
                ? 'คืนค่าแบบฟอร์มตั้งต้นเรียบร้อยแล้ว (เวอร์ชันเดิมยังถูกเก็บไว้ เอกสารเก่าพิมพ์ซ้ำได้เหมือนเดิม)'
                : 'ซิงค์แม่แบบเอกสารตั้งต้นเรียบร้อยแล้ว',
        ]);
    }

    /**
     * รายการตัวแปรที่ใช้ในแม่แบบใบลาได้ พร้อมคำอธิบายไทย
     */
    public function fields()
    {
        return response()->json(LeaveDocumentFields::catalog());
    }

    /**
     * พรีวิวแม่แบบด้วยข้อมูลตัวอย่าง — ไม่แตะข้อมูลจริงและไม่ต้องมีใบลา
     */
    public function preview(Request $request, LeaveDocumentFields $fields)
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
            return response()->json([
                'message' => 'แม่แบบมีข้อผิดพลาด: '.$exception->getMessage(),
            ], 422);
        }

        $pdf = Pdf::loadHTML($html)->setPaper(
            strtolower($data['paper_size'] ?? 'a4'),
            strtolower($data['orientation'] ?? 'portrait')
        );

        return $pdf->stream('preview.pdf');
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
}
