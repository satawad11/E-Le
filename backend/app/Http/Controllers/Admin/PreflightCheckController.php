<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Operations\PreflightReadinessChecker;
use Illuminate\Http\JsonResponse;

/**
 * ตรวจสอบความพร้อมของข้อมูลตั้งต้นทั้งระบบก่อนเปิดใช้งานจริง (Data Onboarding Pre-flight Check)
 *
 * แสดงผลสรุป 7 ด้านสำคัญ พร้อมข้อแนะนำและ action links สำหรับแก้ไขจุดที่ยังไม่ครบถ้วน
 */
class PreflightCheckController extends Controller
{
    public function index(PreflightReadinessChecker $checker): JsonResponse
    {
        $result = $checker->check();

        return response()->json([
            'data' => $result,
        ]);
    }
}
