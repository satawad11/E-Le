<?php

namespace App\Http\Controllers\Overtime;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Overtime\OvertimeAssignment;
use App\Models\Overtime\OvertimeTimeRecord;
use App\Services\Overtime\OvertimeTimeService;
use App\Services\Time\TimeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class OvertimeTimeController extends Controller
{
    public function __construct(
        private readonly OvertimeTimeService $overtime,
        private readonly TimeService $time,
    ) {}

    public function checkIn(Request $request)
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        try {
            $record = $this->overtime->checkIn($request->user(), $data['notes'] ?? null);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }

        return response()->json([
            'message' => 'ลงเวลาเข้า OT สำเร็จ',
            'attendance' => $this->compatibilityAttendance($record),
            'ot_time_record' => $record,
        ]);
    }

    public function checkOut(Request $request)
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        try {
            $record = $this->overtime->checkOut($request->user(), $data['notes'] ?? null);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }

        return response()->json([
            'message' => 'ลงเวลาออก OT สำเร็จ',
            'attendance' => $this->compatibilityAttendance($record),
            'ot_time_record' => $record,
        ]);
    }

    public function certify(Request $request, OvertimeTimeRecord $record)
    {
        $data = $request->validate([
            'certified_start' => ['required', 'date'],
            'certified_end' => ['required', 'date', 'after:certified_start'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $timezone = $record->assignment()->with('order')->firstOrFail()->order->timezone;
        try {
            $result = $this->overtime->certify($record, $request->user(), [
                'certified_start' => CarbonImmutable::parse($data['certified_start'], $timezone),
                'certified_end' => CarbonImmutable::parse($data['certified_end'], $timezone),
                'reason' => $data['reason'],
            ]);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'รับรองเวลาปฏิบัติงาน OT แล้ว', 'ot_time_record' => $result]);
    }

    /**
     * สถานะ OT ของฉันตอนนี้ — มีคำสั่งที่อนุมัติแล้วครอบเวลานี้อยู่หรือไม่ และลงเวลาไปแล้วแค่ไหน
     *
     * CLEANUP-02 — เดิมคืนเฉพาะ "record ล่าสุด" ซึ่งบอกไม่ได้ว่าตอนนี้ลงเวลา OT ได้หรือยัง
     * หน้าจอจึงไม่มีอะไรให้แสดงและไม่เคยเรียก endpoint นี้เลย ผู้ใช้กดปุ่มแล้วเจอ
     * ข้อความปฏิเสธโดยไม่รู้ล่วงหน้าว่าไม่มีคำสั่ง OT
     *
     * เงื่อนไขตรงกับ `OvertimeTimeService::checkIn` ทุกข้อ เพื่อไม่ให้หน้าจอกับหลังบ้านตอบคนละอย่าง
     */
    public function active(Request $request)
    {
        $user = $request->user();
        $now = $this->time->now($user);

        $assignment = OvertimeAssignment::query()
            ->with('order:id,uuid,title,approved_start,approved_end,status')
            ->where('user_id', $user->id)
            ->where('status', 'assigned')
            ->whereHas('order', fn ($query) => $query->where('status', 'approved')
                ->where('approved_start', '<=', $now)
                ->where('approved_end', '>=', $now))
            ->orderByDesc('id')
            ->first();

        $record = OvertimeTimeRecord::with('assignment.order:id,uuid,title')
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        return response()->json([
            'can_check_in' => $assignment !== null,
            'assignment' => $assignment,
            'order' => $assignment?->order,
            'latest_record' => $record,
        ]);
    }

    private function compatibilityAttendance(OvertimeTimeRecord $record): Attendance
    {
        $date = ($record->actual_start ?? $this->time->now($record->user))->setTimezone('Asia/Bangkok')->toDateString();
        $attendance = Attendance::where('user_id', $record->user_id)->whereDate('date', $date)->first()
            ?? new Attendance(['user_id' => $record->user_id, 'date' => $date]);
        $attendance->setAttribute('ot_check_in_time', $record->actual_start);
        $attendance->setAttribute('ot_check_out_time', $record->actual_end);
        $attendance->setAttribute('ot_minutes', $record->actual_minutes ?? 0);

        return $attendance;
    }
}
