<?php

namespace App\Services\DocumentEngine;

/**
 * วันที่ปลอมสำหรับหาตำแหน่งช่องวันที่ของผู้ลงนาม (ใช้ใน LeaveFormLayoutProbe เท่านั้น)
 *
 * แบบฟอร์มพิมพ์วันที่ผู้ลงนามผ่าน `App\Support\FormDate::short()` ซึ่งคืน `$text` ของคลาสนี้
 * — ตัวระบุ (Q001) ตอนหาตำแหน่ง หรือช่องว่างไม่ตัดบรรทัดตอนสร้างแบบฟอร์มเปล่า
 */
class MarkerDate
{
    public function __construct(public readonly string $text) {}
}
