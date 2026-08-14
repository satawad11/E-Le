<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Services\SystemSettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * ตั้งค่าระบบสารบรรณ — Decision 1 / Decision 2 (LEGACY-04 ปิดงาน)
 *
 * เดิมหน้าตั้งค่าสารบรรณเรียก `/api/admin/settings` ซึ่งอยู่ในกลุ่ม route ที่
 * ยอมรับ `manage_settings|system.setting.manage|saraban.setting.manage`
 * แปลว่าใครก็ตามที่ได้ `saraban.setting.manage` จะเข้าถึง **ทุกอย่าง** ในกลุ่มนั้น
 * ได้ด้วย ทั้งค่า SMTP/Telegram/LINE, การล้างค่าลับ, ปีงบประมาณ และเทมเพลตเอกสาร
 *
 * ขัดกับข้อกำหนดด้านความปลอดภัยของงานนี้ที่ว่าการเติมสิทธิ์ต้องไม่ทำให้เห็น
 * ข้อมูลเพิ่มเกินหน้าที่ โดยเฉพาะ "การตั้งค่าระบบ"
 *
 * controller นี้จึงเปิดเฉพาะค่าที่เป็นของงานสารบรรณจริง ๆ และเป็นทางเดียว
 * ที่ `saraban.setting.manage` ใช้ได้ — `/api/admin/settings` กลับไปเป็นของ
 * ผู้ดูแลระบบเท่านั้นตามเดิม
 */
class SettingController extends Controller
{
    /**
     * ค่าที่ผู้ดูแลงานสารบรรณแก้ได้ — ไม่มีค่าลับและไม่มีค่าระดับระบบ
     *
     * @var array<int,string>
     */
    private const KEYS = [
        'require_pin_for_signature',
        'require_pin_for_circular_ack',
        'saraban_incoming_number_format',
        'saraban_outgoing_number_format',
        'saraban_garuda_logo',
    ];

    public function __construct(private readonly SystemSettingService $settings) {}

    public function index()
    {
        return response()->json(
            Arr::only($this->settings->runtimeValues()->all(), self::KEYS)
        );
    }

    public function update(Request $request)
    {
        $rules = Arr::only(config('system_settings.rules', []), self::KEYS);
        $unknown = array_diff(array_keys($request->all()), array_keys($rules));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'settings' => ['ไม่รองรับการตั้งค่า: '.implode(', ', $unknown)],
            ]);
        }

        $validated = $request->validate(Arr::only($rules, array_keys($request->all())));

        foreach (['saraban_incoming_number_format', 'saraban_outgoing_number_format'] as $formatKey) {
            if (filled($validated[$formatKey] ?? null)
                && (! str_contains($validated[$formatKey], '{year}') || ! str_contains($validated[$formatKey], '{running_number}'))) {
                throw ValidationException::withMessages([
                    $formatKey => ['รูปแบบเลขหนังสือต้องมีตัวแปร {year} และ {running_number}'],
                ]);
            }
        }

        $changes = $this->settings->updateMany($validated, $request->user(), $request->ip(), $request->userAgent());

        return response()->json([
            'message' => 'บันทึกการตั้งค่าสารบรรณเรียบร้อยแล้ว',
            'changed_keys' => array_keys($changes),
        ]);
    }

    public function uploadGarudaLogo(Request $request)
    {
        $request->validate(['logo' => 'required|image|mimes:jpeg,png,jpg|max:2048']);

        $oldPath = $this->settings->runtimeValues()->get('saraban_garuda_logo');
        $path = $request->file('logo')->store('logos', 'private_uploads');
        $url = '/storage/'.$path;

        $this->settings->updateMany(['saraban_garuda_logo' => $url], $request->user(), $request->ip(), $request->userAgent());

        if ($oldPath && $oldPath !== $url && str_starts_with($oldPath, '/storage/')) {
            Storage::disk('private_uploads')->delete(substr($oldPath, strlen('/storage/')));
        }

        return response()->json(['message' => 'อัปโหลดตราครุฑเรียบร้อยแล้ว', 'url' => $url]);
    }
}
