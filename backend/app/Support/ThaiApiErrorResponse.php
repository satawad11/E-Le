<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ข้อความ error ของ API ต้องเป็นภาษาไทยเสมอ (REDESIGN-2569 · มาตรฐานภาษาไทย L3)
 *
 * ข้อความที่โค้ดเขียนเองเป็นภาษาไทยอยู่แล้ว เช่น abort(403, 'ห้ามพิจารณาคำขอของตนเอง') — คงไว้
 * ส่วนข้อความตั้งต้นของ Laravel/Symfony ("Unauthenticated.", "This action is unauthorized.",
 * "No query results for model [App\Models\Leave] 5") ถูกแทนด้วยข้อความไทยตามรหัสสถานะ
 * ซึ่งปิดการเปิดเผยชื่อ model และรหัสภายในไปด้วย
 */
class ThaiApiErrorResponse
{
    public const MESSAGES = [
        400 => 'คำขอไม่ถูกต้อง กรุณาตรวจสอบข้อมูลแล้วลองใหม่',
        401 => 'กรุณาเข้าสู่ระบบก่อนใช้งาน',
        403 => 'ท่านไม่มีสิทธิ์ดำเนินการนี้',
        404 => 'ไม่พบข้อมูลที่ต้องการ หรือข้อมูลอาจถูกลบไปแล้ว',
        405 => 'ไม่รองรับวิธีการเรียกใช้นี้',
        409 => 'ข้อมูลถูกเปลี่ยนแปลงโดยผู้อื่นระหว่างนี้ กรุณาโหลดข้อมูลใหม่แล้วลองอีกครั้ง',
        413 => 'ไฟล์หรือข้อมูลมีขนาดใหญ่เกินกำหนด',
        419 => 'เซสชันหมดอายุ กรุณารีเฟรชหน้าจอแล้วลองใหม่',
        422 => 'ข้อมูลที่ระบุไม่ถูกต้องหรือไม่ครบถ้วน',
        429 => 'มีการส่งคำขอถี่เกินไป กรุณารอสักครู่แล้วลองใหม่',
        500 => 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่ภายหลัง หากยังพบปัญหาให้แจ้งผู้ดูแลระบบ',
        503 => 'ระบบปิดปรับปรุงชั่วคราว กรุณาลองใหม่ภายหลัง',
    ];

    public static function apply(Response $response, Request $request): Response
    {
        if (! $request->is('api/*') || ! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
            return $response;
        }

        $data = $response->getData(true);
        if (! is_array($data)) {
            return $response;
        }

        $message = $data['message'] ?? null;
        if (is_string($message) && self::isThai($message)) {
            return $response;
        }

        // เครื่องพัฒนายังเห็นข้อความเดิมเพื่อหาสาเหตุ — production (APP_DEBUG=false) ไม่ส่งออกไป
        if (config('app.debug') && is_string($message) && $message !== '') {
            $data['debug_message'] = $message;
        }
        $data['message'] = self::fallbackFor($response->getStatusCode(), $data);

        return $response->setData($data);
    }

    public static function isThai(string $text): bool
    {
        return (bool) preg_match('/\p{Thai}/u', $text);
    }

    private static function fallbackFor(int $status, array $data): string
    {
        // 422: ใช้ข้อความ validation ข้อแรก (ภาษาไทยจาก lang/th) แทนข้อความสรุปภาษาอังกฤษ
        if ($status === 422 && isset($data['errors']) && is_array($data['errors'])) {
            $first = collect($data['errors'])->flatten()->first(fn ($m) => is_string($m) && self::isThai($m));
            if ($first) {
                return $first;
            }
        }

        return self::MESSAGES[$status] ?? ($status >= 500 ? self::MESSAGES[500] : self::MESSAGES[400]);
    }
}
