<?php

namespace App\Services\Attendance;

use App\Models\Leave;
use App\Models\LeaveType;

/**
 * ใบลงเวลารายเดือน/รายปีแยกประเภทการลาที่นี่ที่เดียว
 *
 * เดิมตรรกะนี้เขียนซ้ำอยู่สองที่ใน `AttendanceController` และตัดสินด้วยการค้นคำในชื่อไทย
 * ของประเภทการลา ซึ่งผู้ดูแลแก้ได้ตลอดเวลา ตอนนี้อ่านจาก `leave_types.attendance_bucket`
 * ที่กำหนดไว้ชัดเจนและแก้ได้จากหน้าจัดการประเภทการลา
 */
class LeaveDayClassifier
{
    /** ช่องในใบลงเวลา => [อักษรย่อที่พิมพ์ในช่องวัน, ชื่อคอลัมน์ยอดรวม] */
    public const BUCKETS = [
        'sick' => ['ป', 'total_sick'],
        'personal' => ['ก', 'total_personal'],
        'maternity' => ['ด', 'total_maternity'],
        'vacation' => ['พ', 'total_vacation'],
        'official' => ['ร', 'total_official'],
        'other' => ['อ', 'total_other'],
    ];

    public const FALLBACK = 'other';

    /** ชื่อช่องสำหรับหน้าจัดการประเภทการลา */
    public const LABELS = [
        'sick' => 'ลาป่วย',
        'personal' => 'ลากิจ',
        'maternity' => 'ลาคลอดบุตร',
        'vacation' => 'ลาพักผ่อน',
        'official' => 'ราชการ',
        'other' => 'อื่น ๆ',
    ];

    public function bucketFor(?LeaveType $type): string
    {
        $bucket = $type?->attendance_bucket;

        return isset(self::BUCKETS[$bucket]) ? $bucket : self::FALLBACK;
    }

    public function symbolFor(?LeaveType $type): string
    {
        return self::BUCKETS[$this->bucketFor($type)][0];
    }

    /** คอลัมน์ยอดรวมทั้งหมด ตั้งต้นเป็น 0 — ผู้เรียกเอาไปบวกทีละวัน */
    public static function emptyTotals(): array
    {
        $totals = [];
        foreach (self::BUCKETS as [$symbol, $column]) {
            $totals[$column] = 0.0;
        }

        return $totals;
    }

    /**
     * วันหนึ่งของใบลาใบนี้นับเป็นวันลากี่วัน — 0 แปลว่าวันนั้นไม่ต้องขึ้นในใบลงเวลา
     *
     * ต้องนับด้วยเกณฑ์เดียวกับตอนตัดโควตาเป๊ะ ๆ:
     *   - นโยบายที่ตัดวันหยุดออก (ลาป่วย ลากิจ ลาพักผ่อน) — วันหยุดที่คร่อมช่วงลาไม่นับ
     *     มิฉะนั้นลา 5 วันทำการที่คร่อมสุดสัปดาห์จะขึ้นในใบลงเวลาเป็น 7 วัน
     *   - นโยบายที่นับต่อเนื่องตามปฏิทิน (ลาคลอดบุตร ลาอุปสมบท) — นับทุกวันรวมวันหยุด
     *     ตามระเบียบ จึงต้องขึ้นในใบลงเวลาครบทุกวันเช่นกัน
     *   - ลาครึ่งวันคืน 0.5 ยอดรวมท้ายใบลงเวลาจึงตรงกับจำนวนวันที่ตัดโควตาไปจริง
     */
    public function dayWeight(Leave $leave, bool $isWorkingDay): float
    {
        $excludesHolidays = $leave->policy?->exclude_holidays ?? true;

        if ($excludesHolidays && ! $isWorkingDay) {
            return 0.0;
        }

        return in_array($leave->leave_period, ['morning', 'afternoon'], true) ? 0.5 : 1.0;
    }
}
