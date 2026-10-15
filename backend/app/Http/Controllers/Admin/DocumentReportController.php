<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document\DocumentReportDefinition;
use App\Services\AuditLogger;
use App\Services\DocumentEngine\DataProviderRegistry;
use App\Services\DocumentEngine\ReportEngine;
use Illuminate\Http\Request;

/**
 * รายงานของศูนย์จัดการเอกสาร
 *
 * ผู้ดูแลสร้างรายงานใหม่ได้จากหน้าจอโดยเลือก "แหล่งข้อมูลที่ระบบมีให้" คอลัมน์ การจัดกลุ่ม
 * และผลรวม — ไม่มีช่องให้เขียน SQL และไม่มีทางดึงข้อมูลเกินสิทธิ์ของผู้เรียก
 * เพราะแหล่งข้อมูลบังคับขอบเขตด้วย ReportingScopeService เดิมอยู่แล้ว
 */
class DocumentReportController extends Controller
{
    public function __construct(
        private readonly ReportEngine $engine,
        private readonly DataProviderRegistry $providers,
        private readonly AuditLogger $audit,
    ) {}

    public function index()
    {
        return response()->json(
            DocumentReportDefinition::where('is_active', true)->orderBy('module')->orderBy('name_th')->get(),
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer|exists:document_report_definitions,id',
            'code' => 'required|string|max:120',
            'name_th' => 'required|string|max:255',
            'module' => 'required|string|max:30',
            'data_source_code' => 'required|string|max:120',
            'filters' => 'nullable|array',
            'columns' => 'nullable|array',
            'columns.*.key' => 'required_with:columns|string|max:120',
            'columns.*.label' => 'nullable|string|max:255',
            'grouping' => 'nullable|array',
            'grouping.column' => 'nullable|string|max:120',
            'aggregates' => 'nullable|array',
            'aggregates.*.column' => 'required_with:aggregates|string|max:120',
            'aggregates.*.function' => 'required_with:aggregates|string|in:COUNT,SUM,AVG,MIN,MAX',
            'layout' => 'nullable|array',
            'orientation' => 'nullable|string|in:portrait,landscape',
            'paper_size' => 'nullable|string|max:20',
        ]);

        // แหล่งข้อมูลต้องเป็นตัวที่ระบบลงทะเบียนไว้เท่านั้น
        abort_unless(
            $this->providers->has($data['data_source_code']),
            422,
            'ไม่รู้จักแหล่งข้อมูลที่เลือก — เลือกได้เฉพาะรายการที่ระบบมีให้',
        );

        $definition = DocumentReportDefinition::updateOrCreate(
            ['id' => $data['id'] ?? null],
            $data + ['created_by' => $request->user()?->id, 'is_active' => true],
        );

        $this->audit->log('document_report_changed', 'DocumentReportDefinition', $definition->id, after: $definition->toArray());

        return response()->json(['message' => 'บันทึกนิยามรายงานเรียบร้อยแล้ว', 'report' => $definition]);
    }

    public function run(Request $request, DocumentReportDefinition $report)
    {
        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $result = $this->engine->run($report, $request->user(), $filters);

        return response()->json([
            'definition' => $result['definition'],
            'columns' => $result['columns'],
            'groups' => $result['groups'],
            'totals' => $result['totals'],
            'meta' => $result['dataset']->meta,
        ]);
    }

    public function export(Request $request, DocumentReportDefinition $report, string $format)
    {
        abort_unless(in_array($format, ['pdf', 'xlsx', 'csv'], true), 422, 'รูปแบบไฟล์ที่ขอไม่รองรับ');

        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $result = $this->engine->run($report, $request->user(), $filters);

        $this->audit->log('document_report_exported', 'DocumentReportDefinition', $report->id, after: ['format' => $format]);

        [$content, $mime, $extension] = match ($format) {
            'pdf' => [$this->engine->pdf($result), 'application/pdf', 'pdf'],
            'xlsx' => [$this->engine->xlsx($result), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'],
            default => [$this->engine->csv($result), 'text/csv; charset=UTF-8', 'csv'],
        };

        return response($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.$report->code.'.'.$extension.'"',
        ]);
    }
}
