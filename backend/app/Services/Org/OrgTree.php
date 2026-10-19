<?php

namespace App\Services\Org;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ต้นไม้หน่วยงาน (CORE-01 · R03-1)
 *
 * - ระดับ L1–L4 อ่านจาก `org_unit_types.level` ไม่ใช่ความลึก (ADR-R-009) ลูกต้องมี level มากกว่า parent
 * - `path` เป็น materialized path (`/1/4/17/`) ใช้ query subtree ด้วย `LIKE` คำสั่งเดียว
 * - ย้าย parent อัปเดต path ของทั้ง subtree ใน transaction · ห้ามวงวน · root ที่ยังมีผลมีได้หน่วยเดียว
 * - ปิดหน่วยด้วย `effective_to` ไม่ลบ (PROJECT_RULES ข้อ 10)
 */
class OrgTree
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{code: string, name: string, org_unit_type_id: int, effective_from: string|CarbonInterface, sort_order?: int}  $attributes
     */
    public function create(array $attributes, ?OrgUnit $parent, ?int $actorId = null): OrgUnit
    {
        return DB::transaction(function () use ($attributes, $parent, $actorId): OrgUnit {
            $type = OrgUnitType::findOrFail($attributes['org_unit_type_id']);

            if ($parent === null) {
                $this->assertNoOtherActiveRoot(null);
            } else {
                $parent = OrgUnit::whereKey($parent->getKey())->lockForUpdate()->firstOrFail();
                $this->assertOpen($parent, 'parent_uuid');
                $this->assertLevelBelow($type, $parent);
            }

            $unit = OrgUnit::create([
                'code' => $attributes['code'],
                'name' => $attributes['name'],
                'org_unit_type_id' => $type->id,
                'parent_id' => $parent?->id,
                'sort_order' => $attributes['sort_order'] ?? 0,
                'effective_from' => $attributes['effective_from'],
            ]);

            $unit->forceFill([
                'path' => ($parent?->path ?? '/').$unit->id.'/',
                'depth' => $parent === null ? 0 : $parent->depth + 1,
            ])->save();

            $this->audit->log('org_unit.created', $unit, null, null, $unit->only(['code', 'name', 'org_unit_type_id', 'parent_id', 'path']), null, $actorId);

            return $unit;
        });
    }

    /**
     * ย้ายหน่วยไปอยู่ใต้ parent ใหม่ พร้อมอัปเดต path/depth ของทุกหน่วยใน subtree
     */
    public function move(OrgUnit $unit, OrgUnit $newParent, ?int $actorId = null): OrgUnit
    {
        return DB::transaction(function () use ($unit, $newParent, $actorId): OrgUnit {
            $unit = OrgUnit::with('type')->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();
            $newParent = OrgUnit::whereKey($newParent->getKey())->lockForUpdate()->firstOrFail();

            if ($newParent->id === $unit->id || str_starts_with($newParent->path, $unit->path)) {
                throw ValidationException::withMessages(['parent_uuid' => 'ย้ายหน่วยไปอยู่ใต้ตัวเองหรือหน่วยลูกของตัวเองไม่ได้ (เกิดวงวน)']);
            }
            if ($unit->parent_id === null) {
                throw ValidationException::withMessages(['parent_uuid' => 'ย้ายหน่วยระดับสถานศึกษา (root) ไม่ได้']);
            }

            $this->assertOpen($newParent, 'parent_uuid');
            $this->assertLevelBelow($unit->type, $newParent);

            $oldPath = $unit->path;
            $newPath = $newParent->path.$unit->id.'/';
            $depthShift = ($newParent->depth + 1) - $unit->depth;
            $before = $unit->only(['parent_id', 'path', 'depth']);

            $descendants = OrgUnit::where('path', 'like', $oldPath.'%')->lockForUpdate()->get();
            foreach ($descendants as $descendant) {
                $descendant->forceFill([
                    'path' => $newPath.substr($descendant->path, strlen($oldPath)),
                    'depth' => $descendant->depth + $depthShift,
                    'parent_id' => $descendant->id === $unit->id ? $newParent->id : $descendant->parent_id,
                ])->save();
            }

            $unit->refresh();
            $this->audit->log('org_unit.moved', $unit, null, $before, $unit->only(['parent_id', 'path', 'depth']), null, $actorId);

            return $unit;
        });
    }

    /**
     * ปิดหน่วย — ต้องปิดหน่วยลูกและสิ้นสุดการดำรงตำแหน่งในหน่วยก่อน เพื่อไม่ให้เกิดคนหรือหน่วยที่ไม่มีที่สังกัด
     */
    public function close(OrgUnit $unit, CarbonInterface|string $on, ?int $actorId = null): OrgUnit
    {
        $date = Carbon::parse($on)->toDateString();

        return DB::transaction(function () use ($unit, $date, $actorId): OrgUnit {
            $unit = OrgUnit::whereKey($unit->getKey())->lockForUpdate()->firstOrFail();
            $this->assertOpen($unit, 'org_unit');

            if ($date < $unit->effective_from->toDateString()) {
                throw ValidationException::withMessages(['effective_to' => 'วันที่ปิดหน่วยต้องไม่ก่อนวันที่เริ่มมีผล']);
            }
            if (OrgUnit::where('parent_id', $unit->id)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))->exists()) {
                throw ValidationException::withMessages(['org_unit' => 'ยังมีหน่วยลูกที่เปิดอยู่ ต้องปิดหรือย้ายหน่วยลูกก่อน']);
            }
            if (Assignment::where('org_unit_id', $unit->id)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))->exists()) {
                throw ValidationException::withMessages(['org_unit' => 'ยังมีผู้ดำรงตำแหน่งในหน่วยนี้ ต้องสิ้นสุดหรือย้ายก่อน']);
            }

            $unit->forceFill(['effective_to' => $date])->save();
            $this->audit->log('org_unit.closed', $unit, null, null, ['effective_to' => $date], null, $actorId);

            return $unit;
        });
    }

    /**
     * หน่วยบรรพบุรุษจากหน่วยที่ใกล้ที่สุดขึ้นไปถึง root (ไม่รวมตัวเอง)
     *
     * @return Collection<int, OrgUnit>
     */
    public function ancestors(OrgUnit $unit): Collection
    {
        $ids = array_reverse($this->pathIds($unit));
        array_shift($ids);

        if ($ids === []) {
            return collect();
        }

        $units = OrgUnit::with('type')->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id) => $units[$id])->values();
    }

    /**
     * id ของหน่วยและหน่วยลูกทั้งหมด
     *
     * @return array<int, int>
     */
    public function subtreeIds(OrgUnit $unit): array
    {
        return OrgUnit::where('path', 'like', $unit->path.'%')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * หน่วยที่ใกล้ที่สุด (รวมตัวเอง) ซึ่งประเภทหน่วยมี level ตรง — หน่วยที่ไม่มีชั้นนั้นในสายคืน null
     */
    public function nearestOfLevel(OrgUnit $unit, int $level): ?OrgUnit
    {
        $unit->loadMissing('type');

        if ($unit->type->level === $level) {
            return $unit;
        }

        return $this->ancestors($unit)->first(fn (OrgUnit $ancestor) => $ancestor->type->level === $level);
    }

    /**
     * ผู้ดำรงตำแหน่งบริหารของหน่วย ณ วันที่ — ถ้ามีผู้รักษาการที่มีผลอยู่ คืนผู้รักษาการ
     */
    public function headOf(OrgUnit $unit, CarbonInterface|string|null $at = null): ?Assignment
    {
        $date = Carbon::parse($at ?? today())->toDateString();

        $acting = Assignment::query()
            ->where('org_unit_id', $unit->id)
            ->where('kind', Assignment::KIND_ACTING)
            ->whereHas('actingFor', fn ($q) => $q->where('kind', Assignment::KIND_MANAGEMENT)->where('org_unit_id', $unit->id))
            ->effectiveOn($date)
            ->orderByDesc('effective_from')
            ->first();

        return $acting ?? Assignment::query()
            ->where('org_unit_id', $unit->id)
            ->where('kind', Assignment::KIND_MANAGEMENT)
            ->effectiveOn($date)
            ->orderByDesc('effective_from')
            ->first();
    }

    /** @return array<int, int> */
    private function pathIds(OrgUnit $unit): array
    {
        return array_map('intval', array_values(array_filter(explode('/', $unit->path), fn ($part) => $part !== '')));
    }

    private function assertLevelBelow(OrgUnitType $childType, OrgUnit $parent): void
    {
        $parent->loadMissing('type');

        if ($childType->level <= $parent->type->level) {
            throw ValidationException::withMessages([
                'org_unit_type' => "ประเภทหน่วย \"{$childType->name}\" (ระดับ {$childType->level}) อยู่ใต้ \"{$parent->type->name}\" (ระดับ {$parent->type->level}) ไม่ได้ ระดับของหน่วยลูกต้องมากกว่าหน่วยแม่",
            ]);
        }
    }

    private function assertOpen(OrgUnit $unit, string $field): void
    {
        if ($unit->effective_to !== null && $unit->effective_to->lt(today())) {
            throw ValidationException::withMessages([$field => "หน่วย \"{$unit->name}\" ปิดแล้ว"]);
        }
    }

    private function assertNoOtherActiveRoot(?int $exceptId): void
    {
        $exists = OrgUnit::whereNull('parent_id')
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['parent_uuid' => 'มีหน่วยระดับสถานศึกษา (root) ที่ยังมีผลอยู่แล้ว ต้องระบุหน่วยแม่']);
        }
    }
}
