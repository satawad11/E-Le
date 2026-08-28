<?php

namespace App\Services\Reporting;

final readonly class ReportDataset
{
    /**
     * @param  array<string, string>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $meta
     */
    /**
     * @param  array<string, string>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals
     * @param  array<string, string>  $totalLabels  ชื่อไทยของแต่ละยอดรวม
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $report,
        public string $title,
        public array $columns,
        public array $rows,
        public array $totals = [],
        public array $meta = [],
        public array $totalLabels = [],
    ) {}

    public function toArray(): array
    {
        return [
            'report' => $this->report,
            'title' => $this->title,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'totals' => $this->totals,
            'total_labels' => $this->totalLabels,
            'meta' => $this->meta,
        ];
    }

    /**
     * ชื่อที่แสดงของยอดรวมหนึ่งรายการ — ใช้ชื่อคอลัมน์ก่อน แล้วค่อยชื่อตัวชี้วัด
     */
    public function totalLabel(string $metric): string
    {
        return $this->columns[$metric] ?? $this->totalLabels[$metric] ?? $metric;
    }
}
