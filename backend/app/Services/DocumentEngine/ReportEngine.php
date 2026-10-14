<?php

namespace App\Services\DocumentEngine;

use App\Models\Document\DocumentReportDefinition;
use App\Models\User;
use App\Services\Reporting\ReportDataset;
use App\Services\Reporting\ReportExportService;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * เรนเดอร์รายงานจาก "นิยามรายงาน" ที่ผู้ดูแลสร้างไว้
 *
 * ข้อมูลมาจาก Data Provider ที่ whitelist ไว้เท่านั้น — นิยามรายงานเลือกได้แค่
 * คอลัมน์ การจัดกลุ่ม และผลรวม ไม่มีช่องทางใดให้ใส่ SQL เอง
 *
 * ขอบเขตการมองเห็นข้อมูลยังเป็นของ provider (ซึ่งใช้ ReportingScopeService เดิม)
 * รายงานที่สร้างใหม่จึงไม่มีทางเห็นข้อมูลกว้างกว่าที่ผู้เรียกมีสิทธิ์เห็น
 */
class ReportEngine
{
    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly ReportExportService $exporter,
    ) {}

    /**
     * ประมวลผลรายงานหนึ่งฉบับ
     *
     * @param  array<string,mixed>  $filters
     * @return array{definition: DocumentReportDefinition, dataset: ReportDataset, groups: array<int,array<string,mixed>>, totals: array<string,float>}
     */
    public function run(DocumentReportDefinition $definition, User $viewer, array $filters = []): array
    {
        $provider = $this->providers->get($definition->data_source_code);

        $dataset = method_exists($provider, 'dataset')
            ? $provider->dataset(['viewer' => $viewer] + $filters)
            : new ReportDataset($definition->code, $definition->name_th, [], []);

        $columns = $this->columns($definition, $dataset);
        $rows = $dataset->rows;

        return [
            'definition' => $definition,
            'dataset' => $dataset,
            'columns' => $columns,
            'groups' => $this->group($definition, $rows, $columns),
            'totals' => $this->aggregate($definition, $rows),
        ];
    }

    /**
     * คอลัมน์ที่รายงานนี้แสดง — ยึดตามนิยาม ถ้ายังไม่ได้กำหนดให้ใช้ทั้งหมดที่ provider ให้มา
     *
     * @return array<string,string>
     */
    private function columns(DocumentReportDefinition $definition, ReportDataset $dataset): array
    {
        $configured = $definition->columns ?? [];

        if ($configured === []) {
            return $dataset->columns;
        }

        $columns = [];

        foreach ($configured as $column) {
            $key = $column['key'] ?? null;

            if ($key !== null && array_key_exists($key, $dataset->columns)) {
                $columns[$key] = $column['label'] ?? $dataset->columns[$key];
            }
        }

        return $columns === [] ? $dataset->columns : $columns;
    }

    /**
     * จัดกลุ่มพร้อมยอดรวมรายกลุ่ม (subtotal)
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<string,string>  $columns
     * @return array<int,array{key: string, label: string, rows: array<int,array<string,mixed>>, subtotal: array<string,float>}>
     */
    private function group(DocumentReportDefinition $definition, array $rows, array $columns): array
    {
        $groupBy = $definition->grouping['column'] ?? null;

        if ($groupBy === null || $rows === []) {
            return [[
                'key' => '',
                'label' => '',
                'rows' => $rows,
                'subtotal' => $this->aggregate($definition, $rows),
            ]];
        }

        $buckets = [];

        foreach ($rows as $row) {
            $key = (string) ($row[$groupBy] ?? '-');
            $buckets[$key][] = $row;
        }

        ksort($buckets);

        $groups = [];

        foreach ($buckets as $key => $bucketRows) {
            $groups[] = [
                'key' => $key,
                'label' => $key,
                'rows' => $bucketRows,
                'subtotal' => $this->aggregate($definition, $bucketRows),
            ];
        }

        return $groups;
    }

    /**
     * ผลรวมตามที่นิยามกำหนด (COUNT/SUM/AVG/MIN/MAX)
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,float>
     */
    private function aggregate(DocumentReportDefinition $definition, array $rows): array
    {
        $result = [];

        foreach ($definition->aggregates ?? [] as $aggregate) {
            $column = $aggregate['column'] ?? null;
            $function = strtoupper((string) ($aggregate['function'] ?? 'SUM'));

            if ($column === null) {
                continue;
            }

            $values = array_map(fn (array $row) => (float) ($row[$column] ?? 0), $rows);

            $result[$column] = match ($function) {
                'COUNT' => (float) count($rows),
                'AVG' => $values === [] ? 0.0 : array_sum($values) / count($values),
                'MIN' => $values === [] ? 0.0 : min($values),
                'MAX' => $values === [] ? 0.0 : max($values),
                default => array_sum($values),
            };
        }

        return $result;
    }

    /** ออกรายงานเป็น PDF (ใช้ฟอนต์ไทยชุดเดียวกับเอกสารอื่นของระบบ) */
    public function pdf(array $report): string
    {
        return Pdf::loadView('pdf.document_report', $report)
            ->setPaper(
                strtolower($report['definition']->paper_size ?: 'a4'),
                strtolower($report['definition']->orientation ?: 'portrait'),
            )
            ->output();
    }

    /** ออกรายงานเป็น Excel — ใช้ตัวส่งออกเดิมของ reporting layer */
    public function xlsx(array $report): string
    {
        $dataset = new ReportDataset(
            report: $report['definition']->code,
            title: $report['definition']->name_th,
            columns: $report['columns'],
            rows: array_merge(...array_map(fn (array $group) => $group['rows'], $report['groups'])) ?: [],
            totals: $report['totals'],
        );

        return $this->exporter->xlsx($dataset);
    }

    public function csv(array $report): string
    {
        $dataset = new ReportDataset(
            report: $report['definition']->code,
            title: $report['definition']->name_th,
            columns: $report['columns'],
            rows: array_merge(...array_map(fn (array $group) => $group['rows'], $report['groups'])) ?: [],
        );

        return $this->exporter->csv($dataset);
    }
}
