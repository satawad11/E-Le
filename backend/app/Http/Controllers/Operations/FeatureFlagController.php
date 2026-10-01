<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\ExceptionQueueException;
use App\Services\Operations\FeatureFlags;
use Illuminate\Http\Request;

/**
 * เปิด-ปิดความสามารถของทั้งระบบ (SoT §21)
 *
 * **ธง ≠ สิทธิ์** — endpoint นี้เปลี่ยน "ความสามารถนี้เปิดอยู่ไหม" เท่านั้น
 * ไม่มีทางใดที่การเปิดธงจะให้สิทธิ์เพิ่มแก่ใคร · ใช้สิทธิ์
 * `system.feature_flags.manage` (เดิม `saraban.feature_flags.manage` ย้ายเป็นของระบบเมื่อถอดสารบรรณ)
 */
class FeatureFlagController extends Controller
{
    public function __construct(private readonly FeatureFlags $flags) {}

    public function index()
    {
        return response()->json(['flags' => $this->flags->overview()]);
    }

    public function update(Request $request, string $key)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        try {
            $this->flags->set($key, $data['enabled'], $request->user());
        } catch (ExceptionQueueException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json(['flags' => $this->flags->overview()]);
    }
}
