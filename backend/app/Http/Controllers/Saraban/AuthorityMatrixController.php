<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Modules\ModuleRegistry;
use App\Services\Saraban\AuthorityResolver;
use Illuminate\Http\Request;

/**
 * ตารางอำนาจหน้าที่ของงานสารบรรณ (SoT §6, §18 · INT-16)
 *
 * ตอบคำถามที่ผู้บริหารถามบ่อยที่สุดสองข้อ:
 *
 *   1. **"ใครลงนามหนังสือของหน่วยงานนี้ได้บ้าง"** — ดูจากความสามารถ
 *   2. **"คนนี้ทำอะไรได้บ้าง และกับหน่วยงานไหน"** — ดูจากคน
 *
 * **อ่านจาก `AuthorityResolver` ซึ่งเป็นตัวตัดสินตัวเดียวกับที่ระบบใช้จริง**
 * ไม่ได้คำนวณสิทธิ์ขึ้นมาใหม่ — ถ้าคำนวณเอง หน้าจอจะบอกความจริงคนละชุด
 * กับที่ระบบใช้ตัดสิน ซึ่งอันตรายกว่าการไม่มีหน้าจอเลย
 *
 * รวมการมอบอำนาจและการรักษาการที่มีผล ณ วันนั้นด้วย เพราะอำนาจจริงของคน
 * ไม่ได้มาจากสิทธิ์ที่ถืออย่างเดียว
 */
class AuthorityMatrixController extends Controller
{
    public function __construct(
        private readonly AuthorityResolver $authority,
        private readonly ModuleRegistry $modules,
    ) {}

    /** อำนาจของผู้ใช้คนหนึ่ง — ค่าเริ่มต้นคือผู้เรียกเอง */
    public function show(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'as_of' => ['nullable', 'date'],
        ]);

        $subject = isset($data['user_id'])
            ? User::query()->findOrFail($data['user_id'])
            : $request->user();

        /*
         * ดูอำนาจของคนอื่นต้องมีสิทธิ์ดูตารางอำนาจ — ดูของตัวเองไม่ต้อง
         * ทุกคนควรตรวจสอบได้ว่าตนทำอะไรได้บ้าง โดยไม่ต้องขอสิทธิ์เพิ่ม
         */
        if ((int) $subject->id !== (int) $request->user()->id) {
            abort_unless($request->user()->can('saraban.authority.view'), 403, 'ไม่มีสิทธิ์ดูอำนาจของผู้อื่น');
        }

        return response()->json([
            'user' => $subject->only(['id', 'name']),
            'matrix' => $this->authority->describeFor($subject, $this->abilities(), $data['as_of'] ?? null),
        ]);
    }

    /** ผู้ถืออำนาจของความสามารถหนึ่ง ในหน่วยงานหนึ่ง */
    public function holders(Request $request)
    {
        $data = $request->validate([
            'ability' => ['required', 'string', 'max:100'],
            'org_unit_id' => ['nullable', 'integer', 'exists:org_units,id'],
            'as_of' => ['nullable', 'date'],
        ]);

        abort_unless(
            in_array($data['ability'], $this->abilities(), true),
            422,
            'ความสามารถนี้ไม่อยู่ในขอบเขตของงานสารบรรณ',
        );

        $holderIds = $this->authority->holdersFor(
            $data['ability'],
            $data['org_unit_id'] ?? null,
            $data['as_of'] ?? null,
        );

        /*
         * `holdersFor` คืนเป็น id ล้วน — เติมชื่อและหน่วยงานให้หน้าจออ่านได้
         * โดยไม่ต้องยิงถามทีละคน · เรียงตามลำดับที่ตัวตัดสินคืนมาไม่มีความหมาย
         * จึงเรียงตามชื่อเพื่อให้หน้าจอคงที่
         */
        return response()->json([
            'ability' => $data['ability'],
            'holders' => User::query()
                ->whereIn('id', $holderIds)
                ->with('orgUnit:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'org_unit_id'])
                ->map(fn (User $user) => [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'org_unit' => $user->orgUnit?->name,
                ]),
        ]);
    }

    /**
     * ความสามารถทั้งหมดของโมดูลสารบรรณ อ่านจาก manifest ที่เดียว
     *
     * ไม่เขียนรายชื่อซ้ำไว้ที่นี่ — เช็คพอยต์ถัดไปที่เพิ่มสิทธิ์ใหม่จะได้ปรากฏ
     * ในตารางอำนาจทันทีโดยไม่ต้องกลับมาแก้ controller
     *
     * @return array<int,string>
     */
    private function abilities(): array
    {
        return array_keys($this->modules->get('saraban')?->permissions() ?? []);
    }
}
