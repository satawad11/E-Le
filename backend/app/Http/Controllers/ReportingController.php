<?php

namespace App\Http\Controllers;

use App\Models\OrgUnit;
use App\Services\AuditLogger;
use App\Services\Reporting\EvaluationMetricsService;
use App\Services\Reporting\ReportExportService;
use App\Services\Reporting\ReportFilter;
use App\Services\Reporting\ReportingDataService;
use App\Support\DocumentFilename;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportingController extends Controller
{
    public function __construct(
        private ReportingDataService $reports,
        private EvaluationMetricsService $evaluation,
        private ReportExportService $exports,
        private AuditLogger $audit,
    ) {}

    /**
     * สารบัญรายงานที่ผู้เรียกเปิดดูได้จริง
     *
     * CLEANUP-01 (D3) — หน้าศูนย์รายงานกลางต้องรู้ว่าผู้ใช้คนนี้เปิดรายงานใดได้บ้าง
     * ก่อนจะวาดเมนู มิฉะนั้นต้องยิงทุกรายงานแล้วรอ 403 ซึ่งทั้งช้าและรก audit log
     */
    public function index(Request $request)
    {
        $viewer = $request->user();

        $reports = collect(ReportingDataService::REPORTS)
            ->filter(fn (string $report) => $report === 'executive'
                ? $this->reports->hasAnyExecutiveSource($viewer)
                : $this->reports->isAvailableTo($report, $viewer))
            ->map(fn (string $report): array => [
                'code' => $report,
                'title' => ReportingDataService::REPORT_TITLES[$report] ?? $report,
            ])
            ->values();

        return response()->json(['reports' => $reports]);
    }

    /**
     * หน่วยงานตามโครงสร้างองค์กรสำหรับช่องตัวกรองขอบเขตของศูนย์รายงานกลาง
     *
     * ตัวกรอง `org_unit_id` อ้างตาราง `org_units` ไม่ใช่ `departments` — ถ้าไม่มี
     * รายการให้เลือก ผู้ใช้ก็ใช้ตัวกรองขอบเขตของชั้นรายงานนี้ไม่ได้เลย
     * ตัวรายงานยังบังคับขอบเขตของผู้เรียกซ้ำอีกชั้นเสมอ รายชื่อนี้จึงไม่ใช่ช่องขยายสิทธิ์
     */
    public function orgUnits()
    {
        return response()->json(
            OrgUnit::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'parent_id'])
        );
    }

    public function show(Request $request, string $report)
    {
        $this->assertReport($report);
        $dataset = $this->reports->build($report, $this->filter($request), $request->user());

        return response()->json($dataset->toArray());
    }

    public function evaluation(Request $request)
    {
        return response()->json($this->evaluation->build($this->filter($request), $request->user()));
    }

    public function export(Request $request, string $report, string $format): Response
    {
        $this->assertReport($report);
        abort_unless(in_array($format, ['pdf', 'xlsx', 'docx', 'csv'], true), 404);

        $dataset = $this->reports->build($report, $this->filter($request), $request->user());

        /*
         * ใช้ชื่อรายงานที่ผู้ใช้เห็นบนหน้าจอเป็นชื่อไฟล์ พร้อมช่วงข้อมูลถ้ามี
         * เดิมได้ชื่อเป็นรหัสภาษาอังกฤษ (`leave-summary-report.pdf`) ซึ่งผู้ใช้ต้องเปิดดู
         * ถึงจะรู้ว่าไฟล์ไหนคือรายงานอะไรและช่วงใด
         */
        $filename = DocumentFilename::make([
            $dataset->title !== '' ? $dataset->title : $report,
            ($dataset->meta['from'] ?? null) && ($dataset->meta['to'] ?? null)
                ? $dataset->meta['from'].'_ถึง_'.$dataset->meta['to']
                : ($dataset->meta['from'] ?? $dataset->meta['to'] ?? null),
        ], $format);
        $this->audit->log('report.exported', 'report', $report, after: [
            'format' => $format,
            'rows' => count($dataset->rows),
            'filters' => array_intersect_key($dataset->meta, array_flip([
                'fiscal_year', 'from', 'to', 'org_unit_id', 'user_id', 'personnel_type_id',
            ])),
        ]);

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('pdf.reporting-generic', ['dataset' => $dataset])
                ->setPaper('a4', 'landscape');

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => DocumentFilename::disposition($filename),
            ]);
        }
        if ($format === 'csv') {
            return response($this->exports->csv($dataset), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => DocumentFilename::disposition($filename),
            ]);
        }

        $path = $format === 'xlsx' ? $this->exports->xlsx($dataset) : $this->exports->docx($dataset);

        // `response()->download()` ประกาศ `filename*` ให้เองอยู่แล้ว ชื่อไทยจึงส่งตรงได้
        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }

    private function filter(Request $request): ReportFilter
    {
        $data = $request->validate([
            'fiscal_year_id' => 'nullable|integer|exists:fiscal_years,id',
            /*
             * รับได้ทั้ง พ.ศ. และ ค.ศ. เพราะหน้าจอส่งเลขปีงบประมาณตามที่ผู้ใช้เห็น (พ.ศ.)
             * ส่วน ReportFilter เป็นผู้แปลงเป็นคีย์ ค.ศ. ของยอดวันลาให้เอง
             * ช่วงเดิม 2000–2200 ปฏิเสธเลข พ.ศ. ทุกค่า จึงใช้พารามิเตอร์นี้กับหน้าจอไม่ได้เลย
             */
            'fiscal_year' => 'nullable|integer|min:2000|max:2700',
            'from' => 'nullable|date|required_with:to',
            'to' => 'nullable|date|required_with:from|after_or_equal:from',
            'org_unit_id' => 'nullable|integer|exists:org_units,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'personnel_type_id' => 'nullable|integer|exists:personnel_types,id',
        ]);

        return ReportFilter::from($data);
    }

    private function assertReport(string $report): void
    {
        abort_unless(in_array($report, ReportingDataService::REPORTS, true), 404);
    }
}
