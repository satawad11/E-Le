<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * บัญชีที่ยังใช้รหัสผ่านชั่วคราวจากผู้ดูแล ต้องเปลี่ยนรหัสก่อนใช้งานระบบ
 *
 * **ทำไมต้องกันที่ฝั่งเซิร์ฟเวอร์ด้วย** — หน้าเว็บพาไปหน้าเปลี่ยนรหัสให้อยู่แล้ว
 * แต่หน้าเว็บไม่ใช่ด่านความปลอดภัย (SoT §6) ผู้ที่ถือโทเคนอยู่ยังยิง API ตรงได้
 * ถ้ากันแค่ฝั่งหน้าจอ รหัสชั่วคราวที่ผู้ดูแลรู้ก็ยังใช้ทำรายการได้ทุกอย่าง
 * ซึ่งเป็นสิ่งเดียวกับที่การบังคับเปลี่ยนรหัสตั้งใจจะปิด
 *
 * เปิดทางไว้เฉพาะสิ่งที่จำเป็นต่อการเปลี่ยนรหัสและออกจากระบบ ไม่งั้นผู้ใช้จะติดอยู่
 * ในสภาพที่ทำอะไรไม่ได้เลยแม้แต่จะแก้เรื่องที่ระบบขอ
 */
class EnsurePasswordIsChanged
{
    /** เส้นทางที่ยังเรียกได้ระหว่างถูกบังคับเปลี่ยนรหัส */
    private const ALLOWED = [
        'api/profile/password',
        'api/logout',
        'api/user',
        'api/settings',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        // อ่านอย่างเดียวยังพอผ่านได้ไหม? ไม่ — ข้อมูลในระบบเป็นข้อมูลส่วนบุคคลทั้งหมด
        if ($this->isAllowed($request)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'กรุณาตั้งรหัสผ่านใหม่ก่อนใช้งานระบบ — รหัสผ่านปัจจุบันเป็นรหัสชั่วคราวที่ผู้ดูแลตั้งให้',
            'must_change_password' => true,
        ], 423);
    }

    private function isAllowed(Request $request): bool
    {
        foreach (self::ALLOWED as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }
}
