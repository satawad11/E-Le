<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\Leave;
use App\Services\Time\TimeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ใบลาที่อนุมัติแล้ว ↔ แถววันลาในตารางลงเวลา
 *
 * ตารางลงเวลาเก็บ "วันลา" เป็นแถวสถานะ `Leave` เพื่อให้ใบลงเวลารายเดือนพิมพ์ออกมาครบ
 * เดิมมีแต่ขาไป (ปุ่มซิงค์ข้อมูลการลา) ไม่มีขากลับ — ใบลาที่ถูกยกเลิก ปฏิเสธ หรือเพิกถอน
 * ทิ้งแถวเหล่านั้นค้างไว้ ใบลงเวลาจึงยังนับเป็นวันลาต่อไปทั้งที่โควตาถูกคืนไปแล้ว
 * ผู้ใช้เห็นเป็น "ยกเลิกแล้วระบบไม่คืนวัน"
 *
 * ทั้งสองทิศทางใช้เกณฑ์นับวันชุดเดียวกับตอนตัดโควตา ผ่าน `LeaveDayClassifier::dayWeight()`
 */
class LeaveAttendanceSync
{
    public function __construct(
        private readonly TimeService $time,
        private readonly LeaveDayClassifier $classifier,
    ) {}

    /** ข้อความหมายเหตุที่ระบุว่าแถวนี้มาจากใบลาใบไหน — ใช้ทั้งตอนเขียนและตอนล้าง */
    public function noteFor(Leave $leave): string
    {
        return 'ลา: '.($leave->type->name ?? 'ไม่ระบุประเภท').' ('.$leave->document_number.')';
    }

    /**
     * รูปแบบหมายเหตุชุดเดิมที่ยังค้างอยู่ในฐานข้อมูล — ไม่มีเลขที่หนังสือกำกับ
     *
     * ต้องรู้จักด้วย มิฉะนั้นแถวที่ซิงค์ไว้ก่อนหน้านี้จะถูกล้างสถานะแต่หมายเหตุยังค้าง
     */
    private function legacyNoteFor(Leave $leave): string
    {
        return 'ลา: '.($leave->type->name ?? 'ไม่ระบุประเภท');
    }

    /**
     * เขียนวันลาของใบนี้ลงตารางลงเวลา
     *
     * ไม่ทับวันที่มีการลงเวลาจริงแล้ว — ถ้ามีทั้งใบลาและการลงเวลาในวันเดียวกัน
     * ให้การลงเวลาชนะ และปล่อยให้ผู้ตรวจสอบเห็นความขัดแย้งนั้นเอง
     *
     * @return int จำนวนวันที่เขียนลงไป
     */
    public function apply(Leave $leave): int
    {
        if ($leave->status !== 'Approved') {
            return 0;
        }

        $written = 0;
        $note = $this->noteFor($leave);

        foreach ($this->countedDays($leave) as $date) {
            $attendance = Attendance::firstOrNew([
                'user_id' => $leave->user_id,
                'date' => $date->toDateString(),
            ]);

            if (in_array($attendance->status, ['Present', 'Late'], true)) {
                continue;
            }

            $attendance->status = 'Leave';
            if (! str_contains($attendance->notes ?? '', $note)) {
                $attendance->notes = blank($attendance->notes) ? $note : $attendance->notes.' | '.$note;
            }
            $attendance->save();
            $written++;
        }

        return $written;
    }

    /**
     * ลบร่องรอยวันลาของใบนี้ออกจากตารางลงเวลา — เรียกเมื่อใบลาถูกยกเลิก ปฏิเสธ หรือเพิกถอน
     *
     * แถวที่มีการลงเวลาจริงจะถูกเก็บไว้ (ลบเฉพาะหมายเหตุ) เพราะเวลาเข้า-ออกเป็นข้อเท็จจริง
     * ที่ไม่เกี่ยวกับใบลา ส่วนแถวที่มีแต่สถานะวันลาจะถูกลบทิ้งทั้งแถว
     *
     * @return int จำนวนวันที่ล้าง
     */
    public function clear(Leave $leave, ?string $from = null, ?string $to = null): int
    {
        $notes = [$this->noteFor($leave), $this->legacyNoteFor($leave)];
        $cleared = 0;

        // ค่าเริ่มต้นคือทั้งใบ — ส่งช่วงวันเข้ามาเมื่อยกเลิกเพียงบางช่วง
        $from = CarbonImmutable::parse($from ?? $leave->start_date)->toDateString();
        $to = CarbonImmutable::parse($to ?? $leave->end_date)->toDateString();

        DB::transaction(function () use ($leave, $notes, $from, $to, &$cleared) {
            // ใช้ whereDate ไม่ใช่ whereBetween — คอลัมน์ date อาจมีส่วนเวลาติดมา
            // ทำให้ขอบบนตัดวันสุดท้ายทิ้งไปเงียบ ๆ
            $rows = Attendance::where('user_id', $leave->user_id)
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->lockForUpdate()
                ->get();

            foreach ($rows as $attendance) {
                $existing = $attendance->notes ?? '';
                $mentionsThisLeave = false;
                foreach ($notes as $candidate) {
                    $mentionsThisLeave = $mentionsThisLeave || str_contains($existing, $candidate);
                }

                if ($attendance->status !== 'Leave' && ! $mentionsThisLeave) {
                    continue;
                }

                $remaining = $this->stripNotes($existing, $notes);

                if ($attendance->check_in_time || $attendance->check_out_time) {
                    // มีการลงเวลาจริง — เก็บข้อเท็จจริงไว้ ตัดเฉพาะหมายเหตุวันลาออก
                    $attendance->notes = $remaining ?: null;
                    $attendance->save();
                    $cleared++;

                    continue;
                }

                if (blank($remaining)) {
                    $attendance->delete();
                } else {
                    // ยังมีใบลาใบอื่นคร่อมวันนี้อยู่ — คงสถานะวันลาไว้ ตัดเฉพาะหมายเหตุของใบนี้
                    $attendance->notes = $remaining;
                    $attendance->save();
                }

                $cleared++;
            }
        }, 5);

        return $cleared;
    }

    /**
     * วันที่ของใบลาใบนี้ที่นับเป็นวันลาจริง
     *
     * @return array<int,CarbonImmutable>
     */
    private function countedDays(Leave $leave): array
    {
        $from = CarbonImmutable::parse($leave->start_date)->startOfDay();
        $to = CarbonImmutable::parse($leave->end_date)->startOfDay();
        $days = [];

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $isWorkingDay = $this->time->isWorkingDay($date, $leave->user);

            if ($this->classifier->dayWeight($leave, $isWorkingDay) > 0) {
                $days[] = $date;
            }
        }

        return $days;
    }

    /** @param  array<int,string>  $notes */
    private function stripNotes(string $existing, array $notes): string
    {
        $parts = array_filter(
            array_map('trim', explode('|', $existing)),
            fn (string $part) => $part !== '' && ! in_array($part, $notes, true),
        );

        return implode(' | ', $parts);
    }
}
