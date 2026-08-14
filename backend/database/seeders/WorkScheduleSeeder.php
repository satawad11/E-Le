<?php

namespace Database\Seeders;

use App\Models\Time\WorkSchedule;
use App\Models\Time\WorkScheduleAssignment;
use App\Models\Time\WorkScheduleDay;
use App\Models\User;
use App\Services\SystemSettingService;
use App\Services\Time\TimeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * LEGACY-06 · 06-2 — สร้างตารางเวลาเริ่มต้นจากข้อมูลที่ระบบมีอยู่แล้ว
 *
 * แหล่งข้อมูลตามลำดับ:
 *   1. setting `default_work_start_time` / `default_work_end_time`
 *   2. setting `start_time` / `end_time` (ชื่อรุ่นเก่าที่ยังใช้อยู่บางที่)
 *   3. เวลาราชการมาตรฐาน 08:30–16:30
 *
 * ผู้ใช้ที่มี `users.work_start_time` เป็นของตัวเองและต่างจากค่าเริ่มต้น
 * จะได้ตารางเฉพาะบุคคลพร้อมการมอบหมายแบบ effective-dated
 *
 * **ไม่แตะ `users.work_start_time` / `work_end_time`** — คงไว้เป็นข้อมูลอ่านอย่างเดียว
 * เพื่อให้ย้อนกลับได้ และเพื่อไม่ให้เกิดแหล่งข้อมูลสองแหล่งที่ขัดกัน
 *
 * idempotent — รันซ้ำได้ ไม่สร้างซ้ำ
 */
class WorkScheduleSeeder extends Seeder
{
    /** เวลาราชการมาตรฐานเมื่อระบบยังไม่ได้ตั้งค่าใด ๆ */
    public const FALLBACK_START = '08:30:00';

    public const FALLBACK_END = '16:30:00';

    public const DEFAULT_CODE = 'standard-office-hours';

    public function run(): void
    {
        $settings = app(SystemSettingService::class)->runtimeValues();

        $start = $this->normalise(
            $settings->get('default_work_start_time') ?: $settings->get('start_time') ?: self::FALLBACK_START
        );
        $end = $this->normalise(
            $settings->get('default_work_end_time') ?: $settings->get('end_time') ?: self::FALLBACK_END
        );

        $schedule = $this->makeSchedule(self::DEFAULT_CODE, 'เวลาราชการปกติ', $start, $end, true);

        // มอบหมายระดับองค์กร — ใช้กับทุกคนที่ไม่มีตารางเฉพาะของตน
        $this->assign($schedule->id, null, null, 'ตารางเริ่มต้นระดับองค์กร (LEGACY-06 · 06-2)');

        $this->backfillPersonalSchedules($start, $end);
    }

    /**
     * ผู้ใช้ที่ตั้งเวลาทำงานเฉพาะตัวไว้ ต้องได้ตารางของตัวเองเพื่อไม่ให้เสียค่าที่เคยตั้ง
     */
    private function backfillPersonalSchedules(string $defaultStart, string $defaultEnd): void
    {
        $users = User::whereNotNull('work_start_time')->get(['id', 'work_start_time', 'work_end_time']);

        foreach ($users as $user) {
            $start = $this->normalise((string) $user->work_start_time);
            $end = $this->normalise((string) ($user->work_end_time ?: $defaultEnd));

            if ($start === $defaultStart && $end === $defaultEnd) {
                continue;   // ตรงกับค่าเริ่มต้นอยู่แล้ว ไม่ต้องมีตารางแยก
            }

            $schedule = $this->makeSchedule(
                'user-'.$user->id.'-hours',
                'เวลาทำงานเฉพาะบุคคล #'.$user->id,
                $start,
                $end,
                false,
            );

            $this->assign(
                $schedule->id,
                $user->id,
                null,
                'backfill จาก users.work_start_time (LEGACY-06 · 06-2)',
            );
        }
    }

    private function makeSchedule(string $code, string $name, string $start, string $end, bool $isDefault): WorkSchedule
    {
        $schedule = WorkSchedule::firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'timezone' => config('app.timezone', TimeService::DEFAULT_TIMEZONE),
                // 06-3: ค่าเริ่มต้น 0 เพื่อรักษาพฤติกรรมเดิม ผู้ดูแลปรับเพิ่มได้ภายหลัง
                'grace_minutes' => 0,
                'early_leave_grace_minutes' => 0,
                'is_default' => $isDefault,
                'is_active' => true,
                'description' => 'สร้างอัตโนมัติจากค่าที่ระบบตั้งไว้เดิม',
            ],
        );

        if ($schedule->days()->exists()) {
            return $schedule;
        }

        $crossesMidnight = strcmp($end, $start) <= 0;

        // 0 = อาทิตย์ ... 6 = เสาร์ — เสาร์อาทิตย์ไม่ใช่วันทำงานตามพฤติกรรมเดิมของระบบ
        foreach (range(0, 6) as $dayOfWeek) {
            $isWorkingDay = $dayOfWeek >= 1 && $dayOfWeek <= 5;

            WorkScheduleDay::create([
                'work_schedule_id' => $schedule->id,
                'day_of_week' => $dayOfWeek,
                'is_working_day' => $isWorkingDay,
                'start_time' => $isWorkingDay ? $start : null,
                'end_time' => $isWorkingDay ? $end : null,
                'break_minutes' => 0,
                'crosses_midnight' => $isWorkingDay && $crossesMidnight,
            ]);
        }

        return $schedule;
    }

    private function assign(int $scheduleId, ?int $userId, ?int $orgUnitId, string $note): void
    {
        $exists = WorkScheduleAssignment::query()
            ->where('work_schedule_id', $scheduleId)
            ->where('user_id', $userId)
            ->where('org_unit_id', $orgUnitId)
            ->exists();

        if ($exists) {
            return;
        }

        WorkScheduleAssignment::create([
            'work_schedule_id' => $scheduleId,
            'user_id' => $userId,
            'org_unit_id' => $orgUnitId,
            /*
             * ย้อนหลังไปไกลพอที่จะครอบข้อมูลการลงเวลาที่มีอยู่แล้ว
             * มิฉะนั้นการคำนวณย้อนหลังจะไม่มีตารางให้ใช้
             */
            'effective_from' => DB::table('attendances')->min('date') ?: now()->startOfYear()->toDateString(),
            'effective_to' => null,
            'note' => $note,
        ]);
    }

    /** รับ H:i หรือ H:i:s แล้วคืน H:i:s เสมอ */
    private function normalise(string $time): string
    {
        $time = trim($time);

        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
