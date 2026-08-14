<?php

namespace App\Models\Time;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * เวลาทำงานของหนึ่งวันในสัปดาห์
 *
 * `day_of_week` ใช้เลขชุดเดียวกับ Carbon (0 = อาทิตย์ ... 6 = เสาร์)
 * เพื่อไม่ต้องแปลงเลขไปมาแล้วผิดพลาด
 */
#[Fillable([
    'work_schedule_id', 'day_of_week', 'is_working_day',
    'start_time', 'end_time', 'break_minutes', 'crosses_midnight',
])]
class WorkScheduleDay extends Model
{
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_working_day' => 'boolean',
            'crosses_midnight' => 'boolean',
            'break_minutes' => 'integer',
        ];
    }

    public function schedule()
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }

    /**
     * กะนี้ข้ามเที่ยงคืนหรือไม่
     *
     * ยึด `crosses_midnight` ที่บันทึกไว้เป็นหลัก และเผื่อกรณีข้อมูลเก่าที่ยังไม่ได้ตั้งค่า
     * ให้อนุมานจากเวลาสิ้นสุดที่ไม่มากกว่าเวลาเริ่ม (เช่น 22:00 → 06:00)
     */
    public function isOvernight(): bool
    {
        if ($this->crosses_midnight) {
            return true;
        }

        if (blank($this->start_time) || blank($this->end_time)) {
            return false;
        }

        return strcmp((string) $this->end_time, (string) $this->start_time) <= 0;
    }
}
