<?php

namespace App\Services\Saraban;

use App\Services\SystemSettingService;
use App\Models\Saraban\DocumentSequence;
use Illuminate\Support\Facades\DB;

class DocumentNumberGeneratorService
{
    /**
     * Calculate Thai Fiscal Year B.E. (1 Oct - 30 Sep).
     * e.g., 15 Oct 2026 -> 2570 B.E.
     *       15 May 2026 -> 2569 B.E.
     */
    public function getThaiFiscalYear(?\Carbon\Carbon $date = null): int
    {
        $date = $date ?? \Carbon\Carbon::now();
        $yearBe = $date->year + 543;
        
        // Fiscal year starts on 1 October
        if ($date->month >= 10) {
            $yearBe += 1;
        }

        return $yearBe;
    }

    /**
     * Generate an atomic running number for the given parameters.
     */
    public function generate(int $departmentId, int $typeId, ?int $year = null): int
    {
        $year = $year ?? $this->getThaiFiscalYear();

        return DB::transaction(function () use ($departmentId, $typeId, $year) {
            $sequence = DocumentSequence::where([
                'department_id' => $departmentId,
                'document_type_id' => $typeId,
                'year_be' => $year,
            ])->lockForUpdate()->first();

            if (!$sequence) {
                $sequence = DocumentSequence::create([
                    'department_id' => $departmentId,
                    'document_type_id' => $typeId,
                    'year_be' => $year,
                    'running_number' => 0,
                ]);
            }

            $sequence->increment('running_number');
            return $sequence->running_number;
        });
    }

    /**
     * Format the running number to a 4-digit string.
     */
    public function format(int $number): string
    {
        return str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    public function formatWithTemplate(int $number, int $yearBe, string $departmentCode, bool $incoming = false): string
    {
        $settings = app(SystemSettingService::class)->runtimeValues();
        $key = $incoming ? 'saraban_incoming_number_format' : 'saraban_outgoing_number_format';
        $fallback = $incoming
            ? '{dept_code}/{year}/{running_number}'
            : '{dept_code}อ/{year}/{running_number}';

        return strtr((string) ($settings->get($key) ?: $fallback), [
            '{dept_code}' => $departmentCode,
            '{year}' => (string) $yearBe,
            '{running_number}' => $this->format($number),
        ]);
    }
}
