<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCommandUsers;
use App\Models\OrgUnit;
use App\Services\AuditLogger;
use App\Services\OrgScopeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * จัดฝ่ายและงานตามระเบียบบริหารสถานศึกษา (ACCESS-GOV-01 · งาน A1 ของแผน)
 *
 *   php artisan org:apply-structure --by=admin@college.ac.th --dry-run
 *   php artisan org:apply-structure --by=admin@college.ac.th
 *
 * - สร้างฝ่าย/งานที่ยังไม่มี (เทียบชื่อตรงตัว) ใต้หน่วยระดับสถานศึกษา
 * - งานที่มีอยู่แล้วแต่ยังไม่อยู่ใต้ฝ่ายของมัน → ย้ายไปไว้ใต้ฝ่าย
 * - แผนกที่ชื่อขึ้นต้นด้วย "แผนกวิชา" และยังอยู่ใต้ระดับสถานศึกษา → ย้ายไปใต้ฝ่ายวิชาการ
 * - ไม่ลบ ไม่เปลี่ยนชื่อ ไม่ปิดหน่วยงานใด ไม่ตั้งหัวหน้า — ที่เหลือรายงานให้ผู้ดูแลจัดการเองที่หน้าโครงสร้างหน่วยงาน
 * - รันซ้ำได้ (idempotent) · ทุกการเปลี่ยนบันทึก audit
 */
class ApplySchoolStructure extends Command
{
    use ResolvesCommandUsers;

    protected $signature = 'org:apply-structure
        {--structure= : รุ่นของระเบียบใน config/school_structure.php (ค่าเริ่มต้นตาม default_version)}
        {--by= : อีเมลหรือ UUID ของผู้ดูแลที่สั่ง (ต้องมีสิทธิ์ system.org_structure.manage)}
        {--with-bachelor : รวมงานที่มีเฉพาะสถานศึกษาที่เปิดระดับปริญญาตรี}
        {--dry-run : แสดงสิ่งที่จะเปลี่ยนโดยไม่บันทึก}';

    protected $description = 'จัดฝ่ายและงานตามระเบียบบริหารสถานศึกษา (สร้าง/ย้าย ไม่ลบ)';

    public function handle(AuditLogger $audit, OrgScopeResolver $scopes): int
    {
        try {
            $actor = $this->findUser((string) $this->option('by'), 'ผู้สั่ง');
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (! $actor->is_active || ! $actor->can('system.org_structure.manage')) {
            $this->error('ผู้สั่งต้องมีสิทธิ์จัดการโครงสร้างหน่วยงาน (system.org_structure.manage)');

            return self::FAILURE;
        }

        $version = (string) ($this->option('structure') ?: config('school_structure.default_version'));
        $structure = config("school_structure.versions.{$version}");
        if (! is_array($structure)) {
            $this->error("ไม่พบโครงสร้างรุ่น {$version} ใน config/school_structure.php");

            return self::FAILURE;
        }

        $root = OrgUnit::whereNull('parent_id')->orderBy('id')->first();
        if ($root === null) {
            $this->error('ไม่พบหน่วยงานระดับสถานศึกษา');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $reason = "จัดโครงสร้างตาม{$structure['title']}";
        $changes = [];

        $run = function () use ($structure, $root, $dry, $actor, $audit, $scopes, $reason, &$changes) {
            $divisionIds = [];

            foreach ($structure['divisions'] as $divisionName => $sections) {
                $division = $this->findActive($divisionName, $root->id)
                    ?? $this->create($divisionName, 'division', $root, $dry, $actor->id, $audit, $reason);
                $changes[] = $division->wasRecentlyCreated || ! $division->exists ? "สร้าง {$divisionName}" : null;
                $divisionIds[$divisionName] = $division;

                foreach ($sections as $section) {
                    $name = is_array($section) ? $section['name'] : $section;
                    if (is_array($section) && ($section['bachelor_only'] ?? false) && ! $this->option('with-bachelor')) {
                        continue;
                    }

                    $existing = $this->findActive($name);
                    if ($existing === null) {
                        $this->create($name, 'section', $division, $dry, $actor->id, $audit, $reason);
                        $changes[] = "สร้าง {$name} ใต้ {$divisionName}";
                    } elseif (! $division->exists || $existing->parent_id !== $division->id) {
                        $this->move($existing, $division, $dry, $actor->id, $audit, $scopes, $reason);
                        $changes[] = "ย้าย {$name} ไปใต้ {$divisionName}";
                    }
                }
            }

            $academic = $divisionIds[$structure['department_division']] ?? null;
            if ($academic !== null) {
                $departments = OrgUnit::where('parent_id', $root->id)->where('is_active', true)
                    ->where('name', 'like', 'แผนกวิชา%')->get();
                foreach ($departments as $department) {
                    if ($academic->exists) {
                        $this->move($department, $academic, $dry, $actor->id, $audit, $scopes, $reason);
                    }
                    $changes[] = "ย้าย {$department->name} ไปใต้ {$academic->name}";
                }
            }
        };

        if ($dry) {
            $run();
        } else {
            DB::transaction($run);
        }

        $changes = array_values(array_filter($changes));
        $this->info(($dry ? '[ทดลอง ยังไม่บันทึก] ' : '').$structure['title'].': เปลี่ยน '.count($changes).' รายการ');
        foreach ($changes as $line) {
            $this->line('  · '.$line);
        }

        $known = collect($structure['divisions'])->keys()->all();
        $leftovers = OrgUnit::where('parent_id', $root->id)->where('is_active', true)
            ->whereNotIn('name', $known)->pluck('name');
        if ($leftovers->isNotEmpty()) {
            $this->warn('หน่วยงานที่ยังอยู่ใต้ระดับสถานศึกษาโดยตรง ต้องย้ายหรือปิดเองที่หน้าโครงสร้างหน่วยงาน:');
            foreach ($leftovers as $name) {
                $this->line('  · '.$name);
            }
        }
        $this->line('ขั้นต่อไป: ตั้งหัวหน้าหน่วยงานพร้อมเลขคำสั่งที่หน้าโครงสร้างหน่วยงาน');

        return self::SUCCESS;
    }

    private function findActive(string $name, ?int $parentId = null): ?OrgUnit
    {
        return OrgUnit::where('name', $name)->where('is_active', true)
            ->when($parentId !== null, fn ($q) => $q->where('parent_id', $parentId))
            ->orderBy('id')->first();
    }

    private function create(string $name, string $type, OrgUnit $parent, bool $dry, int $actorId, AuditLogger $audit, string $reason): OrgUnit
    {
        $unit = new OrgUnit([
            'uuid' => (string) Str::uuid(),
            'parent_id' => $parent->id,
            'code' => 'OU-'.Str::upper(Str::random(10)),
            'name' => $name,
            'type' => $type,
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        if (! $dry && $parent->exists) {
            $unit->save();
            $audit->log('org_unit.created', 'org_unit', $unit->id, null, $unit->only(['uuid', 'parent_id', 'name', 'type']), $reason, $actorId);
        }

        return $unit;
    }

    private function move(OrgUnit $unit, OrgUnit $parent, bool $dry, int $actorId, AuditLogger $audit, OrgScopeResolver $scopes, string $reason): void
    {
        // กันผังวน — ไม่ย้ายหน่วยไปใต้ตัวเองหรือหน่วยย่อยของตัวเอง
        if ($dry || ! $parent->exists) {
            return;
        }
        if ($unit->parent_id === null || in_array($parent->id, $scopes->subtreeIds($unit->id), true)) {
            return;
        }

        $before = ['parent_id' => $unit->parent_id];
        $unit->forceFill(['parent_id' => $parent->id])->save();
        $audit->log('org_unit.updated', 'org_unit', $unit->id, $before, ['parent_id' => $parent->id], $reason, $actorId);
    }
}
