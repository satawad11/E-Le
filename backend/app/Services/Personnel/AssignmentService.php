<?php

namespace App\Services\Personnel;

use App\Events\AssignmentChanged;
use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Personnel;
use App\Models\Position;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * การดำรงตำแหน่ง (CORE-02 · R03-4) — สิทธิ์ผูกกับ assignment ไม่ผูกกับคน
 *
 * กฎ:
 * - `primary` ที่มีผลได้หนึ่งแถวต่อคน ณ เวลาหนึ่ง
 * - `management` ของหน่วยหนึ่งมีผลได้หนึ่งแถว (ไม่นับ acting) และตำแหน่งต้องผูกกับประเภทหน่วยนั้น
 * - `acting` อ้างตำแหน่งบริหารต้นทาง ต้องมีวันสิ้นสุด (เฉพาะช่วงคำสั่ง) และผู้รักษาการไม่ใช่ผู้ดำรงตำแหน่งเอง
 * - ทุกแถวต้องมีเลขที่และวันที่คำสั่ง ยกเว้น `primary` ที่นำเข้าครั้งแรก
 * - ห้ามเขียนทับ: ไม่มีการแก้แถว แก้ได้โดยสิ้นสุดแถวเดิมแล้วเพิ่มแถวใหม่ · ทุกการเปลี่ยนส่ง `AssignmentChanged`
 */
class AssignmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{kind: string, org_unit_id: int, position_id: int, effective_from: string|CarbonInterface, effective_to?: string|CarbonInterface|null, order_number?: ?string, order_date?: string|CarbonInterface|null, acting_for_assignment_id?: ?int}  $data
     */
    public function add(Personnel $personnel, array $data, ?int $actorId = null, bool $initialImport = false): Assignment
    {
        $kind = $data['kind'] ?? null;
        if (! in_array($kind, Assignment::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'ประเภทการดำรงตำแหน่งไม่ถูกต้อง']);
        }

        $from = Carbon::parse($data['effective_from'])->toDateString();
        $to = isset($data['effective_to']) ? Carbon::parse($data['effective_to'])->toDateString() : null;

        if ($to !== null && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => 'วันสิ้นสุดต้องไม่ก่อนวันเริ่มมีผล']);
        }
        if (empty($data['order_number']) || empty($data['order_date'])) {
            if (! ($kind === Assignment::KIND_PRIMARY && $initialImport)) {
                throw ValidationException::withMessages(['order_number' => 'ต้องระบุเลขที่และวันที่คำสั่ง']);
            }
        }

        return DB::transaction(function () use ($personnel, $data, $kind, $from, $to, $actorId): Assignment {
            // ล็อกแถวบุคลากรเพื่อกันการเพิ่มตำแหน่งซ้อนพร้อมกัน
            $personnel = Personnel::whereKey($personnel->getKey())->lockForUpdate()->firstOrFail();

            if ($personnel->status === 'separated') {
                throw ValidationException::withMessages(['personnel' => 'บุคลากรพ้นสภาพแล้ว เพิ่มตำแหน่งไม่ได้']);
            }

            $actingFor = null;
            if ($kind === Assignment::KIND_ACTING) {
                $actingFor = $this->actingTarget($personnel, $data, $to);
                $orgUnit = $actingFor->orgUnit;
                $position = $actingFor->position;
            } else {
                $orgUnit = OrgUnit::with('type')->findOrFail($data['org_unit_id']);
                $position = Position::findOrFail($data['position_id']);
            }

            $this->assertUnitOpenOn($orgUnit, $from);
            $this->assertPositionFits($kind, $position, $orgUnit);
            $this->assertNoOverlap($personnel, $kind, $orgUnit, $position->id, $actingFor, $from, $to);

            $assignment = Assignment::create([
                'personnel_id' => $personnel->id,
                'kind' => $kind,
                'org_unit_id' => $orgUnit->id,
                'position_id' => $position->id,
                'acting_for_assignment_id' => $actingFor?->id,
                'order_number' => $data['order_number'] ?? null,
                'order_date' => $data['order_date'] ?? null,
                'effective_from' => $from,
                'effective_to' => $to,
                'created_by' => $actorId,
            ]);

            $this->audit->log('assignment.created', $assignment, null, null, $assignment->only([
                'personnel_id', 'kind', 'org_unit_id', 'position_id', 'acting_for_assignment_id', 'order_number', 'effective_from', 'effective_to',
            ]), null, $actorId);

            DB::afterCommit(fn () => AssignmentChanged::dispatch($assignment, AssignmentChanged::CREATED));

            return $assignment;
        });
    }

    /**
     * สิ้นสุดการดำรงตำแหน่ง — ปิดแถวด้วยวันที่ ผู้ปิด และเหตุผล ไม่ลบ ไม่ขยายวันที่
     */
    public function end(Assignment $assignment, CarbonInterface|string $on, string $reason, ?int $actorId = null): Assignment
    {
        $date = Carbon::parse($on)->toDateString();

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['end_reason' => 'ต้องระบุเหตุผลการสิ้นสุด']);
        }

        return DB::transaction(function () use ($assignment, $date, $reason, $actorId): Assignment {
            $assignment = Assignment::whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();

            if ($assignment->ended_by !== null || $assignment->end_reason !== null) {
                throw ValidationException::withMessages(['assignment' => 'การดำรงตำแหน่งนี้สิ้นสุดไปแล้ว']);
            }
            if ($date < $assignment->effective_from->toDateString()) {
                throw ValidationException::withMessages(['effective_to' => 'วันสิ้นสุดต้องไม่ก่อนวันเริ่มมีผล']);
            }
            if ($assignment->effective_to !== null && $date > $assignment->effective_to->toDateString()) {
                throw ValidationException::withMessages(['effective_to' => 'ขยายวันสิ้นสุดไม่ได้ ต้องออกคำสั่งใหม่']);
            }

            $before = $assignment->only(['effective_to']);
            $assignment->forceFill(['effective_to' => $date, 'ended_by' => $actorId, 'end_reason' => $reason])->save();

            $this->audit->log('assignment.ended', $assignment, null, $before, ['effective_to' => $date, 'end_reason' => $reason], $reason, $actorId);

            DB::afterCommit(fn () => AssignmentChanged::dispatch($assignment, AssignmentChanged::ENDED));

            return $assignment;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function actingTarget(Personnel $personnel, array $data, ?string $to): Assignment
    {
        if (empty($data['acting_for_assignment_id'])) {
            throw ValidationException::withMessages(['acting_for_assignment_id' => 'การรักษาการต้องระบุตำแหน่งบริหารต้นทาง']);
        }
        if ($to === null) {
            throw ValidationException::withMessages(['effective_to' => 'การรักษาการต้องมีวันสิ้นสุดตามคำสั่ง']);
        }

        $target = Assignment::with(['orgUnit.type', 'position'])->findOrFail($data['acting_for_assignment_id']);

        if ($target->kind !== Assignment::KIND_MANAGEMENT) {
            throw ValidationException::withMessages(['acting_for_assignment_id' => 'รักษาการได้เฉพาะตำแหน่งบริหาร']);
        }
        if ($target->personnel_id === $personnel->id) {
            throw ValidationException::withMessages(['acting_for_assignment_id' => 'ผู้ดำรงตำแหน่งรักษาการตำแหน่งของตนเองไม่ได้']);
        }

        return $target;
    }

    private function assertUnitOpenOn(OrgUnit $unit, string $date): void
    {
        if ($unit->effective_from->toDateString() > $date || ($unit->effective_to !== null && $unit->effective_to->toDateString() < $date)) {
            throw ValidationException::withMessages(['org_unit_id' => "หน่วย \"{$unit->name}\" ไม่มีผล ณ วันที่ {$date}"]);
        }
    }

    private function assertPositionFits(string $kind, Position $position, OrgUnit $unit): void
    {
        if (! $position->is_active) {
            throw ValidationException::withMessages(['position_id' => "ตำแหน่ง \"{$position->name}\" ปิดใช้งานแล้ว"]);
        }

        $needsManagement = in_array($kind, [Assignment::KIND_MANAGEMENT, Assignment::KIND_ACTING], true);

        if ($needsManagement && $position->kind !== Position::KIND_MANAGEMENT) {
            throw ValidationException::withMessages(['position_id' => 'ตำแหน่งในสายบังคับบัญชาต้องเป็นตำแหน่งบริหาร']);
        }
        if ($needsManagement && $position->org_unit_type_id !== $unit->org_unit_type_id) {
            throw ValidationException::withMessages(['position_id' => "ตำแหน่ง \"{$position->name}\" ไม่ใช่ตำแหน่งหัวหน้าของหน่วยประเภทนี้"]);
        }
        if ($kind === Assignment::KIND_PRIMARY && $position->kind !== Position::KIND_LINE) {
            throw ValidationException::withMessages(['position_id' => 'ตำแหน่งหลักต้องเป็นตำแหน่งงาน (line) ตำแหน่งบริหารบันทึกเป็น management']);
        }
    }

    private function assertNoOverlap(Personnel $personnel, string $kind, OrgUnit $unit, int $positionId, ?Assignment $actingFor, string $from, ?string $to): void
    {
        $overlapping = fn (Builder $q) => $q
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->when($to !== null, fn ($w) => $w->where('effective_from', '<=', $to));

        $conflict = match ($kind) {
            Assignment::KIND_PRIMARY => Assignment::where('personnel_id', $personnel->id)->where('kind', Assignment::KIND_PRIMARY)->where($overlapping)->exists()
                ? 'บุคลากรมีตำแหน่งหลักที่มีผลในช่วงนี้อยู่แล้ว ต้องสิ้นสุดตำแหน่งหลักเดิมก่อน'
                : null,
            Assignment::KIND_MANAGEMENT => Assignment::where('org_unit_id', $unit->id)->where('kind', Assignment::KIND_MANAGEMENT)->where($overlapping)->exists()
                ? "หน่วย \"{$unit->name}\" มีผู้ดำรงตำแหน่งบริหารในช่วงนี้อยู่แล้ว"
                : null,
            Assignment::KIND_ACTING => Assignment::where('acting_for_assignment_id', $actingFor?->id)->where('kind', Assignment::KIND_ACTING)->where($overlapping)->exists()
                ? 'มีผู้รักษาการตำแหน่งนี้ในช่วงเวลาซ้อนกันอยู่แล้ว'
                : null,
            // หน้าที่มอบหมายมีได้หลายแถว แต่หน้าที่เดียวกันในหน่วยเดียวกันซ้อนช่วงเวลาไม่ได้
            Assignment::KIND_DUTY => Assignment::where('personnel_id', $personnel->id)->where('kind', Assignment::KIND_DUTY)
                ->where('org_unit_id', $unit->id)->where('position_id', $positionId)->where($overlapping)->exists()
                ? 'บุคลากรมีหน้าที่นี้ในหน่วยนี้ในช่วงเวลาซ้อนกันอยู่แล้ว'
                : null,
        };

        if ($conflict !== null) {
            throw ValidationException::withMessages(['effective_from' => $conflict]);
        }
    }
}
