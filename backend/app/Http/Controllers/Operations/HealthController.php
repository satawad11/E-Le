<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\SystemHealth;

/**
 * จุดตรวจสุขภาพระบบ (SoT §27)
 *
 * **แยกสองระดับโดยตั้งใจ**
 *
 *   `summary()`  เปิดสาธารณะ คืนแค่ ok/degraded/down — สำหรับ uptime monitor
 *                ภายนอกที่ต้องเรียกได้โดยไม่มีบัญชีผู้ใช้ (ปิด RISK-54 ในส่วน
 *                ที่โค้ดทำได้)
 *
 *   `details()`  ต้องมีสิทธิ์ — รายละเอียดของแต่ละชิ้นเป็น **ข้อมูลภายใน**
 *                ที่บอกผู้โจมตีว่าระบบใช้อะไรและตรงไหนกำลังอ่อนแอ
 *
 * ถ้ารวมสองอย่างไว้ที่เดียว ต้องเลือกระหว่าง "monitor เรียกไม่ได้" กับ
 * "เปิดเผยสถาปัตยกรรมภายในให้ทุกคน" ซึ่งทั้งคู่ยอมรับไม่ได้
 */
class HealthController extends Controller
{
    public function __construct(private readonly SystemHealth $health) {}

    /** สาธารณะ — คืน 503 เมื่อระบบใช้งานไม่ได้ เพื่อให้ monitor จับได้จาก status code */
    public function summary()
    {
        $summary = $this->health->summary();

        return response()->json(
            $summary,
            $summary['status'] === SystemHealth::DOWN ? 503 : 200,
        );
    }

    /** ต้องมีสิทธิ์ — รายละเอียดของทุกชิ้น */
    public function details()
    {
        return response()->json([
            'checked_at' => now()->toIso8601String(),
            'summary' => $this->health->summary(),
            'checks' => $this->health->checks(),
        ]);
    }
}
