<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ชีพจรของงานตามเวลาหนึ่งงาน (ปิด RISK-40 · SoT §20)
 *
 * งานที่ตั้งเวลาไว้จะเงียบสนิทเมื่อ `schedule:run` ไม่ถูกเรียก — ไม่มี error
 * ไม่มี log · ตารางนี้ทำให้ความเงียบนั้นกลายเป็นสิ่งที่ตรวจจับได้
 */
#[Fillable(['task_key', 'last_run_at', 'expected_interval_minutes', 'meta'])]
class ScheduledTaskHeartbeat extends Model
{
    protected $table = 'scheduled_task_heartbeats';

    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
            'expected_interval_minutes' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * เงียบเกินกำหนดหรือยัง
     *
     * เผื่อเวลาเป็นสองเท่าของรอบที่คาดไว้ก่อนถือว่าผิดปกติ — งานที่รันทุก 5 นาที
     * อาจช้าไปหนึ่งรอบเพราะเครื่องยุ่ง ซึ่งไม่ใช่ความผิดปกติที่ต้องปลุกคน
     */
    public function isStale(): bool
    {
        return $this->last_run_at->addMinutes($this->expected_interval_minutes * 2)->isPast();
    }
}
