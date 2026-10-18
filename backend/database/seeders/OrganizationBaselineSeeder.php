<?php

namespace Database\Seeders;

use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * R02-9 — หน่วยงานตั้งต้น: root + 4 ฝ่าย (CORE-01 §Seed ตั้งต้น)
 *
 * ที่มาของฝ่าย: ระเบียบ สอศ. ว่าด้วยการบริหารสถานศึกษา พ.ศ. 2569 ข้อ 8–12 และแผนภูมิท้ายระเบียบ
 * งาน/แผนกวิชานำเข้าใน R-03 จากไฟล์ที่งานบุคคลยืนยัน — seeder นี้ไม่สร้างหน่วยระดับ 3 ขึ้นไป
 *
 * ชื่อหน่วยเป็นข้อมูล ผู้ดูแลแก้ได้ ไม่มีตรรกะใดอ่านชื่อเหล่านี้
 * idempotent: ค้นตาม `code` ไม่ทับชื่อที่ผู้ดูแลแก้ไว้แล้ว
 */
class OrganizationBaselineSeeder extends Seeder
{
    public const ROOT = ['code' => 'ORG-ROOT', 'name' => 'วิทยาลัยเทคนิคดอนเมือง'];

    public const DIVISIONS = [
        ['code' => 'DIV-RESOURCES', 'name' => 'ฝ่ายบริหารทรัพยากร'],
        ['code' => 'DIV-STRATEGY', 'name' => 'ฝ่ายยุทธศาสตร์และแผนงาน'],
        ['code' => 'DIV-STUDENT-AFFAIRS', 'name' => 'ฝ่ายกิจการนักเรียน นักศึกษา'],
        ['code' => 'DIV-ACADEMIC', 'name' => 'ฝ่ายวิชาการ'],
    ];

    /** วันประกาศระเบียบ พ.ศ. 2569 (24 มี.ค. 2569) — ผู้ดูแลปรับวันที่มีผลจริงได้ใน R-03 */
    public const EFFECTIVE_FROM = '2026-03-24';

    public function run(): void
    {
        DB::transaction(function (): void {
            $institution = OrgUnitType::where('code', 'institution')->firstOrFail();
            $division = OrgUnitType::where('code', 'division')->firstOrFail();

            $root = $this->ensure(self::ROOT, $institution, null, 0);

            foreach (self::DIVISIONS as $order => $unit) {
                $this->ensure($unit, $division, $root, $order + 1);
            }
        });
    }

    /** @param  array{code: string, name: string}  $unit */
    private function ensure(array $unit, OrgUnitType $type, ?OrgUnit $parent, int $sortOrder): OrgUnit
    {
        $orgUnit = OrgUnit::firstOrCreate(
            ['code' => $unit['code']],
            [
                'name' => $unit['name'],
                'org_unit_type_id' => $type->id,
                'parent_id' => $parent?->id,
                'sort_order' => $sortOrder,
                'effective_from' => self::EFFECTIVE_FROM,
            ],
        );

        // materialized path ต้องรู้ id ของตนเองก่อน จึงตั้งหลังสร้างแถว
        $path = ($parent?->path ?? '/').$orgUnit->id.'/';
        $depth = $parent === null ? 0 : $parent->depth + 1;

        if ($orgUnit->path !== $path || $orgUnit->depth !== $depth) {
            $orgUnit->forceFill(['path' => $path, 'depth' => $depth])->save();
        }

        return $orgUnit;
    }
}
