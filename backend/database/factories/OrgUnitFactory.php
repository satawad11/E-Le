<?php

namespace Database\Factories;

use App\Models\OrgUnit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * หน่วยงานในผังองค์กรสำหรับชุดทดสอบ
 *
 * เดิมเทสต์สร้างหน่วยงานผ่าน `Department::create(['name' => ...])` ซึ่งอาศัย observer
 * มิเรอร์เข้าผังให้เอง · ผังเหลือชุดเดียวแล้ว หน่วยงานจึงถูกสร้างตรงเข้า `org_units`
 *
 * ค่าเริ่มต้นแขวนใต้ยอดผังถ้ามี เพื่อให้พฤติกรรมเหมือนที่ระบบจริงสร้างหน่วยงาน
 */
class OrgUnitFactory extends Factory
{
    protected $model = OrgUnit::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'parent_id' => OrgUnit::whereNull('parent_id')->value('id'),
            'code' => 'ORG-'.Str::upper(Str::random(8)),
            'name' => 'หน่วยงานทดสอบ '.Str::random(4),
            'type' => 'department',
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
            'is_active' => true,
        ];
    }

    /** หน่วยงานบนสุดของผัง — ไม่มีหน่วยงานแม่ */
    public function root(): static
    {
        return $this->state(fn () => ['parent_id' => null, 'type' => 'organization']);
    }
}
