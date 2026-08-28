<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * ผ่านเมื่อผู้เรียกมี "อย่างน้อยหนึ่ง" ในความสามารถที่ระบุ
 *
 * CLEANUP-02 (D5) — รวมทางตัดสินสิทธิ์ให้เหลือทางเดียว
 *
 * เดิม route ใช้ `permission:` ของ Spatie ซึ่งตรวจชื่อสิทธิ์ตรง ๆ ไม่ผ่าน Gate จึง
 *
 *   1. ไม่รู้จัก "ชื่อที่เทียบเท่ากัน" (`permission_aliases`) ทุกที่จึงต้องเขียนทั้งชื่อ
 *      ชุดเดิมและชุดมาตรฐานคู่กันเสมอ เช่น `manage_settings|system.setting.manage`
 *   2. ไม่รู้จัก explicit deny (`permission_denies`) — สิทธิ์ที่ถูกตัดเฉพาะรายจึงยัง
 *      ผ่านด่านนี้ได้ ทั้งที่ `can:` ปฏิเสธไปแล้ว
 *
 * middleware นี้เรียกผ่าน `Gate` เหมือน `can:` ทุกประการ ต่างเพียงรับได้หลายชื่อ
 * สำหรับกรณีที่ความสามารถ "ต่างกันจริง" และยอมรับได้ทั้งคู่
 * (เช่น ดูเวลาของตัวเอง หรือ ดูเวลาทั้งองค์กร)
 *
 * ส่วนกรณีที่เป็นแค่ชื่อเก่ากับชื่อใหม่ของสิ่งเดียวกัน ให้ใช้ `can:` ชื่อมาตรฐานพอ
 */
class EnsureAnyAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        foreach ($abilities as $ability) {
            foreach (explode('|', $ability) as $name) {
                if (Gate::allows(trim($name))) {
                    return $next($request);
                }
            }
        }

        abort(403, 'ไม่มีสิทธิ์เข้าถึงรายการนี้');
    }
}
