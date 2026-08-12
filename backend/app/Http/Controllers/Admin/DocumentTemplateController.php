<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use Illuminate\Http\Request;

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
    public function seedDefaults()
    {
        $defaults = [
            [
                'code' => 'leave_sick_personal',
                'name' => 'แบบใบลาป่วย ลาคลอดบุตร ลากิจส่วนตัว',
                'module' => 'leave',
                'content_html' => view('pdf.leave')->render(),
            ],
            [
                'code' => 'leave_vacation',
                'name' => 'แบบใบลาพักผ่อน',
                'module' => 'leave',
                'content_html' => view('pdf.leave_vacation')->render(),
            ],
            [
                'code' => 'official_travel',
                'name' => 'บันทึกข้อความ ขออนุญาตเดินทางไปราชการ',
                'module' => 'leave',
                'content_html' => view('pdf.official_travel')->render(),
            ],
            [
                'code' => 'leave_cancel',
                'name' => 'แบบใบขอยกเลิกวันลา',
                'module' => 'leave',
                'content_html' => view('pdf.leave_cancel')->render(),
            ],
            [
                'code' => 'hr_employment_certificate',
                'name' => 'หนังสือรับรองการปฏิบัติงานบุคลากร (ตัวอย่าง HR)',
                'module' => 'hr',
                'content_html' => '<div class="text-center"><h2>หนังสือรับรองการปฏิบัติงาน</h2><p>ขอรับรองว่า {{ $user_name }} สังกัด {{ $department }}</p></div>',
            ],
        ];

        foreach ($defaults as $d) {
            DocumentTemplate::firstOrCreate(['code' => $d['code']], $d);
        }

        return response()->json(['message' => 'ซิงค์แม่แบบเอกสารตั้งต้นเรียบร้อยแล้ว']);
    }
}
