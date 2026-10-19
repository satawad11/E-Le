<?php

namespace App\Support\Labels;

/**
 * ป้ายภาษาไทยของ `leave_action_logs.action` (REDESIGN-2569 · มาตรฐานภาษาไทย L4)
 *
 * ค่าที่เก็บเป็นรหัสภาษาอังกฤษและใช้ในตรรกะ/รายงานเดิม จึงไม่เปลี่ยนค่าในฐานข้อมูล
 * แต่ API ส่ง `action_label` ให้หน้าจอแสดงแทน — เพิ่มที่นี่ทุกครั้งที่มีค่าใหม่ (มี test ตรวจ)
 */
class LeaveActionLabel
{
    public const LABELS = [
        'Created' => 'ยื่นใบลา',
        'Created & Approved' => 'บันทึกวันลาแทนและอนุมัติ',
        'Updated' => 'แก้ไขใบลา',
        'Cancelled' => 'ยกเลิกใบลา',
        'Cancellation Requested' => 'ขอยกเลิกวันลา',
        'Cancellation Step Approved' => 'เห็นชอบคำขอยกเลิกวันลา',
        'Cancellation Approved' => 'อนุญาตให้ยกเลิกวันลา',
        'Cancellation Rejected' => 'ไม่อนุญาตให้ยกเลิกวันลา',
        'HR Verified' => 'งานบุคลากรตรวจสอบแล้ว',
        'Substitute Accepted' => 'ผู้ปฏิบัติงานแทนรับมอบงาน',
        'Substitute Rejected' => 'ผู้ปฏิบัติงานแทนปฏิเสธ',
    ];

    public static function label(?string $action): string
    {
        if ($action === null || $action === '') {
            return 'ไม่ระบุการดำเนินการ';
        }

        // ค่าในสายยกเลิกต่อท้ายลำดับขั้น เช่น "Cancellation Approved (Level 2)"
        if (preg_match('/^(.*) \(Level (\d+)\)$/', $action, $m) && isset(self::LABELS[$m[1]])) {
            return self::LABELS[$m[1]].' (ขั้นที่ '.$m[2].')';
        }

        return self::LABELS[$action] ?? 'การดำเนินการอื่น';
    }
}
