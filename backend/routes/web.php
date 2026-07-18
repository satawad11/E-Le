<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/fix-leaves', function () {
    $leaves = \App\Models\Leave::whereNull('document_number')->orderBy('created_at', 'asc')->get();
    $count = 0;
    foreach ($leaves as $leave) {
        $prefix = $leave->type ? ($leave->type->code_prefix ?: 'L') : 'L';
        $yearMonth = \Carbon\Carbon::parse($leave->created_at)->format('Ym');
        
        $lastLeave = \App\Models\Leave::where('document_number', 'like', "{$prefix}-{$yearMonth}-%")
            ->orderBy('id', 'desc')
            ->first();

        $sequence = 1;
        if ($lastLeave && preg_match('/-(\d+)$/', $lastLeave->document_number, $matches)) {
            $sequence = intval($matches[1]) + 1;
        }

        $leave->document_number = sprintf('%s-%s-%04d', $prefix, $yearMonth, $sequence);
        $leave->save();
        $count++;
    }
    return "Successfully updated {$count} leaves.";
});
