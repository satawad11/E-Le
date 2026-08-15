<?php

namespace App\Services\Overtime;

use App\Models\Attendance;
use App\Models\Overtime\OvertimeTimeRecord;

class OvertimeAttendanceReconciliationService
{
    public function reconcile(OvertimeTimeRecord $record): OvertimeTimeRecord
    {
        $order = $record->assignment->order;
        $anchor = $record->actual_start ?? $order->approved_start ?? $order->scheduled_start;
        $date = $anchor->setTimezone($order->timezone)->toDateString();
        $attendance = Attendance::where('user_id', $record->user_id)->whereDate('date', $date)->first();

        $record->update([
            'attendance_id' => $attendance?->id,
            'reconciliation_status' => $attendance ? 'matched' : 'missing_attendance',
            'reconciliation_details' => [
                'work_date' => $date,
                'attendance_id' => $attendance?->id,
                'legacy_ot_check_in_time' => $attendance?->getRawOriginal('ot_check_in_time'),
                'legacy_ot_check_out_time' => $attendance?->getRawOriginal('ot_check_out_time'),
                'legacy_ot_minutes' => $attendance?->getRawOriginal('ot_minutes'),
            ],
            'reconciled_at' => now(),
        ]);

        return $record->refresh();
    }
}
