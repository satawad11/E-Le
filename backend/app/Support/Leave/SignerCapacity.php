<?php

namespace App\Support\Leave;

/**
 * ฐานะของผู้ลงนามบนแบบฟอร์มราชการ — ใช้เลือกบล็อก "ผู้ตรวจสอบ" และชื่อผู้บริหารจากหน้าตั้งค่า
 *
 * ย้ายมาจาก ApprovalStep (ตาราง approval_steps ถูกตัดใน R-02) เพื่อให้ document engine ใช้ต่อได้
 * R-08 ให้ resolver ตามระดับหน่วย (CORE-05) เป็นผู้กำหนดฐานะของแต่ละขั้น
 */
final class SignerCapacity
{
    public const CHECKER = 'checker';

    public const SUPERVISOR = 'supervisor';

    public const DEPUTY = 'deputy';

    public const DIRECTOR = 'director';
}
