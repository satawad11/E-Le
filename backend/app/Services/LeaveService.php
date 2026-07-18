<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use App\Models\Holiday;

class LeaveService
{
    /**
     * Calculate total leave days excluding weekends, and optionally holidays.
     */
    public function calculateTotalDays($startDate, $endDate, $excludeHolidays = true): float
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $totalDays = 0;

        $holidays = [];
        if ($excludeHolidays) {
            $holidays = Holiday::where('is_active', true)
                ->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
                ->pluck('date')
                ->map(fn($date) => $date->format('Y-m-d'))
                ->toArray();
        }

        $current = $start->copy();
        while ($current->lte($end)) {
            $dateStr = $current->format('Y-m-d');
            $isHoliday = $excludeHolidays && in_array($dateStr, $holidays);

            if ($current->isWeekday() && !$isHoliday) {
                $totalDays += 1;
            }
            $current->addDay();
        }

        return $totalDays < 0.5 ? 0.5 : (float) $totalDays;
    }

    /**
     * Store a new leave request.
     */
    public function createLeaveRequest(array $data, int $userId, ?UploadedFile $attachment): Leave
    {
        $leaveType = LeaveType::find($data['leave_type_id']);
        
        $leavePeriod = $data['leave_period'] ?? 'full_day';
        if ($leavePeriod !== 'full_day') {
            $totalDays = 0.5;
        } else {
            $totalDays = $this->calculateTotalDays($data['start_date'], $data['end_date'], $leaveType->exclude_holidays);
        }

        $attachmentPath = null;
        if ($attachment) {
            $attachmentPath = $attachment->store('attachments', 'public');
        }

        $prefix = $leaveType->code_prefix ?: 'L';
        $yearMonth = date('Ym');

        $lastLeave = Leave::where('leave_type_id', $leaveType->id)
            ->where('document_number', 'like', "{$prefix}-{$yearMonth}-%")
            ->orderBy('id', 'desc')
            ->first();

        $sequence = 1;
        if ($lastLeave && preg_match('/-(\d+)$/', $lastLeave->document_number, $matches)) {
            $sequence = intval($matches[1]) + 1;
        }

        $documentNumber = sprintf("%s-%s-%04d", $prefix, $yearMonth, $sequence);

        return Leave::create([
            'user_id'         => $userId,
            'leave_type_id'   => $data['leave_type_id'],
            'document_number' => $documentNumber,
            'start_date'      => $data['start_date'],
            'end_date'        => $data['end_date'],
            'leave_period'    => $leavePeriod,
            'total_days'      => $totalDays,
            'reason'          => $data['reason'],
            'contact_address' => $data['contact_address'] ?? null,
            'attachment'      => $attachmentPath,
            'status'          => 'Pending',
            'approval_level'  => 1,
            'substitute_id'     => $data['substitute_id'] ?? null,
            'substitute_status' => isset($data['substitute_id']) ? 'Pending' : null,
            'substitute_reason' => $data['substitute_reason'] ?? null,
            'meta_data'         => isset($data['meta_data']) ? json_decode($data['meta_data'], true) : null,
            'signature_path'    => $data['signature_path'] ?? null,
        ]);
    }

    /**
     * Deduct leave balance when a leave is fully approved.
     * Returns false if balance is insufficient.
     */
    public function deductBalance(Leave $leave): bool
    {
        $balance = LeaveBalance::where('user_id', $leave->user_id)
            ->where('leave_type_id', $leave->leave_type_id)
            ->where('year', Carbon::parse($leave->start_date)->year)
            ->first();

        if (!$balance) {
            return false;
        }

        $remaining = $balance->quota - $balance->used;
        if ($remaining < $leave->total_days) {
            return false;
        }

        $balance->used += $leave->total_days;
        $balance->save();

        return true;
    }

    /**
     * Restore leave balance when a leave is cancelled or rejected after being approved.
     */
    public function restoreBalance(Leave $leave): void
    {
        $balance = LeaveBalance::where('user_id', $leave->user_id)
            ->where('leave_type_id', $leave->leave_type_id)
            ->where('year', Carbon::parse($leave->start_date)->year)
            ->first();

        if ($balance) {
            $balance->used = max(0, $balance->used - $leave->total_days);
            $balance->save();
        }
    }

    /**
     * Check if the user has enough leave balance.
     */
    public function hasEnoughBalance(Leave $leave): bool
    {
        $balance = LeaveBalance::where('user_id', $leave->user_id)
            ->where('leave_type_id', $leave->leave_type_id)
            ->where('year', Carbon::parse($leave->start_date)->year)
            ->first();

        if (!$balance) {
            return false;
        }

        return ($balance->quota - $balance->used) >= $leave->total_days;
    }
}
