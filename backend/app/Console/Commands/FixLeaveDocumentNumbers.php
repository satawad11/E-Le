<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Leave;
use Carbon\Carbon;

class FixLeaveDocumentNumbers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leaves:fix-document-numbers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix and backfill missing document numbers for leaves';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $leaves = Leave::whereNull('document_number')->orderBy('created_at', 'asc')->get();
        $count = 0;

        foreach ($leaves as $leave) {
            $prefix = $leave->type ? ($leave->type->code_prefix ?: 'L') : 'L';
            $yearMonth = Carbon::parse($leave->created_at)->format('Ym');
            
            $lastLeave = Leave::where('document_number', 'like', "{$prefix}-{$yearMonth}-%")
                ->orderBy('id', 'desc')
                ->first();

            $sequence = 1;
            if ($lastLeave && preg_match('/-(\d+)$/', $lastLeave->document_number, $matches)) {
                $sequence = intval($matches[1]) + 1;
            }

            $leave->document_number = sprintf('%s-%s-%04d', $prefix, $yearMonth, $sequence);
            $leave->save();
            $count++;
            $this->info("Updated leave ID {$leave->id} to {$leave->document_number}");
        }

        $this->info("Successfully updated {$count} leaves.");
    }
}
