<?php

namespace App\Services\Saraban;

use App\Models\Saraban\DocumentSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DocumentNumberGeneratorService
{
    /**
     * Generate an atomic running number for the given parameters.
     *
     * The sequence row is created outside the locking transaction so that two
     * concurrent first-ever requests for the same (year, department, type) cannot
     * both fall through `lockForUpdate()->first()` returning null and each insert
     * their own row. The unique index `sequence_unique` is the backstop: if the
     * insert loses the race we simply re-read the row the winner created.
     */
    public function generate(int $departmentId, int $typeId, int $year): int
    {
        $keys = [
            'department_id' => $departmentId,
            'document_type_id' => $typeId,
            'year_be' => $year,
        ];

        try {
            DocumentSequence::firstOrCreate($keys, ['running_number' => 0]);
        } catch (QueryException $e) {
            // Another request inserted the same key first; its row is what we want.
            if (!DocumentSequence::where($keys)->exists()) {
                throw $e;
            }
        }

        return DB::transaction(function () use ($keys) {
            $sequence = DocumentSequence::where($keys)->lockForUpdate()->firstOrFail();
            $sequence->increment('running_number');

            return $sequence->running_number;
        });
    }

    /**
     * The Buddhist-era year a running number is filed under.
     *
     * The college files by Thai CALENDAR year (พ.ศ.), not by fiscal year — so this
     * deliberately ignores FiscalYear even when one is flagged `is_current`, which
     * is used elsewhere for leave quotas. Single source of truth: callers must not
     * derive the year themselves or the register ends up mixing both schemes.
     */
    public function currentYearBe(): int
    {
        return (int) date('Y') + 543;
    }

    /**
     * Format the running number to a 4-digit string.
     */
    public function format(int $number): string
    {
        return str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}
