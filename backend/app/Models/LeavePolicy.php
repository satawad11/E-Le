<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeavePolicy extends Model
{
    protected $guarded = [];

    /*
     * LS-03 — วันที่มีผลต้องเป็น "วันที่" ไม่ใช่จุดเวลา
     *
     * cast แบบ `date` เดิมทำให้ JSON ออกมาเป็น `2025-09-30T17:00:00.000000Z` (เที่ยงคืนไทย
     * แปลงเป็น UTC) หน้าจอที่ตัด 10 ตัวแรกจึงได้วันที่ถอยไปหนึ่งวันทุกครั้งที่เปิดแก้ไข
     * และถ้าผู้ดูแลกดบันทึกโดยไม่ได้แก้อะไร วันมีผลก็เลื่อนจริงในฐานข้อมูล
     */
    protected $casts = ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'eligibility_rules' => 'array', 'allow_past_dates' => 'boolean', 'exclude_holidays' => 'boolean', 'allow_half_day' => 'boolean', 'requires_substitute' => 'boolean', 'is_carry_forward' => 'boolean', 'is_active' => 'boolean', 'annual_quota' => 'float', 'max_days_per_request' => 'float', 'max_days_with_pay' => 'float', 'attachment_required_after_days' => 'float', 'max_carry_forward' => 'float', 'max_accumulated' => 'float'];

    public function personnelType()
    {
        return $this->belongsTo(PersonnelType::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }
}
