<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Modules\ModuleRegistry;
use App\Services\SystemSettingService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * เปิด-ปิดโมดูลของระบบ
 *
 * CLEANUP-02 — รวมกลไกเปิด-ปิดโมดูลให้เหลือชุดเดียว
 *
 * เดิมหน้า "โมดูลระบบ" เขียนค่าตั้งค่า `enable_leave_menu` / `enable_attendance_menu`
 * ซึ่งเคยใช้ซ่อนเมนูที่เขียนตายตัวใน `App.vue` เมื่อแถบเมนูเปลี่ยนไปอ่านจากตาราง `menus`
 * (ซึ่งกรองด้วย `ModuleRegistry`) ค่าสองตัวนั้นก็ไม่มีอะไรอ่านอีกเลย — สวิตช์ในหน้านั้น
 * จึงไม่มีผลกับสิ่งใดทั้งสิ้น ขณะที่กลไกจริงตั้งได้จาก env เท่านั้น
 *
 * ตอนนี้ค่าตั้งค่า `module_{configKey}_enabled` เป็นตัวกำหนดจริง โดย `AppServiceProvider`
 * เติมทับ `config('modules.*.enabled')` ตอน boot — เมนู, ด่าน route และ Notification Center
 * จึงเห็นค่าเดียวกันทั้งหมด
 */
class ModuleController extends Controller
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly SystemSettingService $settings,
    ) {}

    public function index()
    {
        return response()->json(['modules' => array_values($this->modules->describe())]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'module' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
        ]);

        $module = collect($this->modules->all())
            ->first(fn ($definition) => $definition->configKey === $data['module'] || $definition->code() === $data['module']);

        if ($module === null) {
            throw ValidationException::withMessages(['module' => ['ไม่รู้จักโมดูลนี้']]);
        }

        if (! $module->canDisable()) {
            throw ValidationException::withMessages([
                'module' => ['โมดูลแกนของระบบปิดไม่ได้ เพราะโมดูลอื่นทั้งหมดพึ่งพาอยู่'],
            ]);
        }

        /*
         * ปิดโมดูลที่ตัวอื่นพึ่งพาอยู่ = ปิดตัวที่พึ่งพาไปด้วยโดยปริยาย (ModuleRegistry
         * ตรวจ dependency ให้แล้ว) แจ้งให้ผู้ดูแลรู้ล่วงหน้าแทนที่จะให้ไปเจอเอาเอง
         */
        $dependants = collect($this->modules->all())
            ->filter(fn ($definition) => in_array($module->code(), $definition->dependencies(), true))
            ->map(fn ($definition) => $definition->toArray()['name_th'] ?? $definition->code())
            ->values()
            ->all();

        $this->settings->updateMany(
            ["module_{$module->configKey}_enabled" => $data['enabled'] ? '1' : '0'],
            $request->user(),
            $request->ip(),
            $request->userAgent(),
        );

        config(["modules.{$module->configKey}.enabled" => $data['enabled']]);

        return response()->json([
            'message' => $data['enabled'] ? 'เปิดใช้งานโมดูลแล้ว' : 'ปิดโมดูลแล้ว',
            'affected_dependants' => $data['enabled'] ? [] : $dependants,
            'modules' => array_values($this->modules->describe()),
        ]);
    }
}
