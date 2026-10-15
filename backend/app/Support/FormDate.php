<?php

namespace App\Support;

use App\Services\DocumentEngine\MarkerDate;

/**
 * วันที่ลงนามบนแบบฟอร์ม (วว/ดด/พ.ศ.) — ใช้ในแบบฟอร์มใบลาทุกแบบ
 *
 * รองรับวันที่ปลอมของ LeaveFormLayoutProbe ด้วย เพื่อให้หาตำแหน่งช่องวันที่บนแบบฟอร์มได้
 */
class FormDate
{
    public static function short(mixed $date): string
    {
        if ($date instanceof MarkerDate) {
            return $date->text;
        }

        if ($date instanceof \DateTimeInterface) {
            return $date->format('d/m/').((int) $date->format('Y') + 543);
        }

        return '';
    }
}
