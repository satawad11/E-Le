<?php

namespace App\Services;

use App\Models\LeaveDocumentSequence;
use Illuminate\Support\Facades\DB;

class LeaveDocumentNumberService
{
    public function next(string $prefix, int $buddhistYear): string
    {
        return DB::transaction(function () use ($prefix, $buddhistYear) {
            LeaveDocumentSequence::firstOrCreate(['prefix' => $prefix, 'year' => $buddhistYear], ['last_number' => 0]);
            $row = LeaveDocumentSequence::where('prefix', $prefix)->where('year', $buddhistYear)->lockForUpdate()->firstOrFail();
            $row->increment('last_number');

            return sprintf('%s-%d-%05d', $prefix, $buddhistYear, $row->fresh()->last_number);
        }, 5);
    }
}
