<?php

namespace App\Services\Overtime;

use App\Models\Overtime\OvertimeAssignment;
use App\Models\Overtime\OvertimeTimeRecord;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Time\TimeService;
use Illuminate\Support\Facades\DB;

class OvertimeTimeService
{
    public function __construct(
        private readonly TimeService $time,
        private readonly OvertimeAttendanceReconciliationService $reconciliation,
        private readonly OvertimeOrderService $orders,
        private readonly AuditLogger $audit,
    ) {}

    public function checkIn(User $user, ?string $notes = null): OvertimeTimeRecord
    {
        $now = $this->time->now($user);
        $assignment = OvertimeAssignment::query()
            ->with('order')
            ->where('user_id', $user->id)->where('status', 'assigned')
            ->whereHas('order', fn ($query) => $query->where('status', 'approved')
                ->where('approved_start', '<=', $now)->where('approved_end', '>=', $now))
            ->orderByDesc('id')->first();
        if ($assignment === null) {
            throw new \DomainException('คุณไม่มีคำสั่ง OT ที่ได้รับอนุมัติในช่วงเวลานี้');
        }

        return DB::transaction(function () use ($assignment, $user, $now, $notes) {
            $record = OvertimeTimeRecord::where('ot_assignment_id', $assignment->id)->lockForUpdate()->first();
            if ($record?->actual_start !== null) {
                throw new \DomainException('คุณได้ลงเวลาเข้า OT แล้ว');
            }
            $record ??= new OvertimeTimeRecord([
                'ot_assignment_id' => $assignment->id, 'user_id' => $user->id, 'source' => 'web',
            ]);
            $record->fill(['actual_start' => $now, 'status' => 'in_progress', 'notes' => $notes])->save();
            $this->audit->log('overtime.actual.started', $record, null, null, ['actual_start' => $now], $notes, $user->id);

            return $this->reconciliation->reconcile($record);
        }, 5);
    }

    public function checkOut(User $user, ?string $notes = null): OvertimeTimeRecord
    {
        $now = $this->time->now($user);

        return DB::transaction(function () use ($user, $now, $notes) {
            $record = OvertimeTimeRecord::with('assignment.order')
                ->where('user_id', $user->id)->where('status', 'in_progress')
                ->lockForUpdate()->latest('id')->first();
            if ($record === null || $record->actual_start === null) {
                throw new \DomainException('คุณยังไม่ได้ลงเวลาเข้า OT');
            }
            if ($record->actual_end !== null) {
                throw new \DomainException('คุณได้ลงเวลาออก OT แล้ว');
            }
            $minutes = (int) $record->actual_start->diffInMinutes($now);
            $record->update([
                'actual_end' => $now, 'actual_minutes' => $minutes, 'status' => 'recorded',
                'notes' => blank($notes) ? $record->notes : trim(($record->notes ? $record->notes.' | ' : '').$notes),
            ]);
            $record->assignment->update(['status' => 'completed']);
            $this->audit->log('overtime.actual.completed', $record, null, null, ['actual_end' => $now, 'actual_minutes' => $minutes], $notes, $user->id);

            return $this->reconciliation->reconcile($record);
        }, 5);
    }

    public function certify(OvertimeTimeRecord $record, User $actor, array $data): OvertimeTimeRecord
    {
        $record->loadMissing('assignment.order');
        $this->orders->authorizeReviewer($record->assignment->order, $actor);
        if ($record->actual_start === null || $record->actual_end === null) {
            throw new \DomainException('ต้องลงเวลาปฏิบัติงานจริงให้ครบก่อนรับรองเวลา OT');
        }
        $start = $data['certified_start'];
        $end = $data['certified_end'];
        $minutes = (int) $start->diffInMinutes($end);

        $before = $record->only(['certified_start', 'certified_end', 'certified_minutes', 'status']);
        $record->update([
            'certified_start' => $start, 'certified_end' => $end, 'certified_minutes' => $minutes,
            'status' => 'certified', 'certified_by' => $actor->id, 'certified_at' => now(),
        ]);
        $this->audit->log('overtime.time.certified', $record, null, $before, [
            'certified_start' => $start, 'certified_end' => $end, 'certified_minutes' => $minutes,
        ], $data['reason'], $actor->id);

        return $record->refresh();
    }
}
