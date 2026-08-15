<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\Reporting\EvaluationMetricsService;
use App\Services\Reporting\ReportExportService;
use App\Services\Reporting\ReportFilter;
use App\Services\Reporting\ReportingDataService;
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
        $filename = "{$report}-report";
        $this->audit->log('report.exported', 'report', $report, after: [
            'format' => $format,
            'rows' => count($dataset->rows),
            'filters' => array_intersect_key($dataset->meta, array_flip([
                'fiscal_year', 'from', 'to', 'org_unit_id', 'user_id', 'personnel_type_id',
            ])),
        ]);

        if ($format === 'pdf') {
            return Pdf::loadView('pdf.reporting-generic', ['dataset' => $dataset])
                ->setPaper('a4', 'landscape')
                ->download("{$filename}.pdf");
        }
        if ($format === 'csv') {
            return response($this->exports->csv($dataset), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}.csv\"",
            ]);
        }

        $path = $format === 'xlsx' ? $this->exports->xlsx($dataset) : $this->exports->docx($dataset);

        return response()->download($path, "{$filename}.{$format}")->deleteFileAfterSend(true);
    }

    private function filter(Request $request): ReportFilter
    {
        $data = $request->validate([
            'fiscal_year_id' => 'nullable|integer|exists:fiscal_years,id',
            'fiscal_year' => 'nullable|integer|min:2000|max:2200',
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
