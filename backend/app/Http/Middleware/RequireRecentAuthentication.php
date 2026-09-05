<?php

namespace App\Http\Middleware;

use App\Services\StepUpAuthentication;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ด่านยืนยันตัวตนซ้ำก่อนทำสิ่งที่อ่อนไหว (SoT §24 · SEC-GAP-01)
 *
 * ใช้เป็น middleware alias `step_up` ที่ route ของการกระทำที่ SoT §24 ระบุไว้
 * เช่น การลงนาม การเพิกถอนเลขที่ออกไปแล้ว และการเผยแพร่นโยบาย
 *
 * คืน **423 Locked** พร้อมรหัสที่หน้าเว็บอ่านได้ (`reauthentication_required`)
 * เพื่อให้หน้าจอเปิดกล่องยืนยันรหัสผ่านได้เอง แทนที่จะแสดงเป็นข้อผิดพลาดทั่วไป
 * — 423 สื่อว่า "ทำได้ แต่ต้องปลดล็อกก่อน" ซึ่งตรงกับสถานการณ์นี้มากกว่า 401/403
 */
class RequireRecentAuthentication
{
    public function __construct(private readonly StepUpAuthentication $stepUp) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->stepUp->isRecent($request->user())) {
            return $next($request);
        }

        return response()->json([
            'message' => 'การดำเนินการนี้ต้องยืนยันตัวตนอีกครั้งก่อน',
            'code' => 'reauthentication_required',
            'window_minutes' => $this->stepUp->windowMinutes(),
        ], 423);
    }
}
