<?php

namespace App\Events;

use App\Models\Personnel;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * สถานะบุคลากรเปลี่ยน (CORE-02) — ผู้ฟัง: JML ของบัญชี (R-05 · AccountLifecycleService)
 *
 * ส่งหลัง commit จาก Personnel::updated ทุกทาง (แก้ประวัติ นำเข้า คำขอแก้ไขที่อนุมัติ)
 */
class PersonnelStatusChanged
{
    use Dispatchable;

    public function __construct(public readonly Personnel $personnel, public readonly ?string $from) {}
}
