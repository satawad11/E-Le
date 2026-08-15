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
    public function __construct(
        public string $report,
        public string $title,
        public array $columns,
        public array $rows,
        public array $totals = [],
        public array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'report' => $this->report,
            'title' => $this->title,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'totals' => $this->totals,
            'meta' => $this->meta,
        ];
    }
}
