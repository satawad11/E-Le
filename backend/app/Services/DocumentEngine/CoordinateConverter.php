<?php

namespace App\Services\DocumentEngine;

/**
 * แปลงหน่วยและระบบพิกัดของแบบฟอร์ม
 *
 * ระบบเก็บพิกัดเป็น **PDF point วัดจากมุมซ้ายบน** เพราะเป็นมุมมองเดียวกับที่ผู้ดูแล
 * เห็นบนหน้าจอตอนลากฟิลด์ ส่วน FPDI ทำงานด้วยหน่วยที่ประกาศตอนสร้างเอกสาร
 * การแปลงจึงรวมไว้ที่เดียว ไม่ให้กระจายไปตามผู้เรียก
 */
class CoordinateConverter
{
    public const POINTS_PER_INCH = 72.0;

    public const MM_PER_INCH = 25.4;

    public static function mmToPoints(float $mm): float
    {
        return $mm / self::MM_PER_INCH * self::POINTS_PER_INCH;
    }

    public static function pointsToMm(float $points): float
    {
        return $points / self::POINTS_PER_INCH * self::MM_PER_INCH;
    }

    /**
     * พิกัดสำหรับวาดจริง หลังรวมค่าชดเชยเครื่องพิมพ์
     *
     * @return array{0: float, 1: float}
     */
    public static function withCalibration(float $x, float $y, float $xOffset, float $yOffset, float $scale): array
    {
        $scale = $scale > 0 ? $scale : 1.0;

        return [($x * $scale) + $xOffset, ($y * $scale) + $yOffset];
    }
}
