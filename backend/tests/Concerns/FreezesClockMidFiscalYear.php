<?php

namespace Tests\Concerns;

use Carbon\Carbon;

/**
 * ตรึงนาฬิกาของเทสต์ไว้กลางปีงบประมาณ
 *
 * **ปัญหาจริงที่กันไว้ (พบ 2026-10-01)** — เทสต์กลุ่มนี้สร้างสิทธิ์วันลาด้วย
 * `'year' => now()->year` (ปีปฏิทิน) แล้วยื่นใบลาที่ "วันนี้ + ไม่กี่วัน" แต่คีย์ของ
 * `leave_balances.year` คือปีที่ปีงบประมาณสิ้นสุด (`LeavePolicyService::fiscalYearNumber`)
 * ตั้งแต่ 1 ตุลาคมถึง 31 ธันวาคม ใบลาจึงตกปีงบประมาณถัดไปและหา balance ไม่เจอ
 * ทำให้เทสต์ล้มเองสามเดือนต่อปีโดยไม่มีใครแก้อะไรผิด (แบบเดียวกับ commit 8307f5b)
 *
 * 15 มกราคม อยู่กลางปีงบประมาณ ห่างทั้งวันขึ้นปีงบประมาณและปีปฏิทิน
 * Laravel คืนนาฬิกาจริงให้เองใน tearDown ของ TestCase
 */
trait FreezesClockMidFiscalYear
{
    protected const MID_FISCAL_YEAR_CLOCK = '2026-01-15 09:00:00';

    protected function freezeClockMidFiscalYear(): void
    {
        Carbon::setTestNow(self::MID_FISCAL_YEAR_CLOCK);
    }
}
