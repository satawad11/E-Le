<?php

namespace App\Services\Saraban;

use App\Models\Saraban\DocumentSequence;
use Illuminate\Support\Facades\DB;

class DocumentNumberGeneratorService
{
    /**
     * Generate an atomic running number for the given parameters.
     */
    public function generate(int $departmentId, int $typeId, int $year): int
    {
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
}
