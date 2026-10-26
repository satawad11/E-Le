<?php

namespace App\Authz\Workflow;

use App\Models\Assignment;
use App\Models\Authz\RoleGrant;
use App\Models\OrgUnit;
use App\Models\Personnel;
use App\Models\User;
use App\Services\Org\OrgTree;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * คำนวณสายอนุมัติจริงของผู้ยื่นหนึ่งคน (CORE-05 · REBUILD-2569 · R04-7)
 *
 * ขั้น (step) อ้างระดับหน่วยหรือชุดสิทธิ์ ไม่อ้างชื่อ role:
 *   ['resolver' => 'head_of_level', 'level' => 3]            หัวหน้าหน่วยระดับ 3 ที่ใกล้ที่สุดที่หน่วยต้นสังกัดของผู้ยื่นหรือเหนือขึ้นไป
 *   ['resolver' => 'functional_template', 'template' => 'personnel.hr_officer']  ผู้ถือ role grant ของชุดสิทธิ์นั้น
 *   ['resolver' => 'specific_assignment', 'assignment_uuid' => '...']            ผู้ดำรงตำแหน่งที่ระบุ (ถ้ายังมีผล)
 *
 * กติกา:
 * - ไม่พบผู้อนุมัติ หรือผู้อนุมัติคือผู้ยื่น → ข้ามขั้น (`skip_policy` = next เป็นค่าเริ่มต้น; `queue` = ส่งคิวทันที)
 * - หมดทุกขั้นแล้วไม่มีผู้อนุมัติเลย → `unresolved` = true (เข้าคิว "ไม่พบผู้อนุมัติ" ของงานบุคคล) **ห้าม fallback ตามชื่อ role**
 * - ขั้นติดกันที่เป็นคนเดียวกันรวมเป็นขั้นเดียว แต่บันทึกทุกตำแหน่งที่ผ่าน
 * - ทุกขั้นบันทึก assignment ที่ใช้ลงนาม (รวม acting) เพื่อออกเอกสารด้วยชื่อตำแหน่งที่ถูกต้อง
 */
class ApproverChainResolver
{
    public function __construct(private readonly OrgTree $tree) {}

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array{steps: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>, unresolved: bool}
     */
    public function resolve(Personnel $requester, array $steps, ?string $on = null): array
    {
        $date = Carbon::parse($on ?? today())->toDateString();
        $home = Assignment::with('orgUnit.type')->where('personnel_id', $requester->id)
            ->where('kind', Assignment::KIND_PRIMARY)->effectiveOn($date)->first()?->orgUnit;

        return $this->resolveFrom($home, $requester->id, $steps, $date);
    }

    /**
     * สายอนุมัติของบุคลากรที่สังกัดหน่วยนี้ (หน้าโครงสร้างองค์กร R-06) — กติกาเดียวกับ resolve()
     * ไม่ระบุผู้ยื่น จึงไม่ตัดขั้นที่ "ผู้อนุมัติคือผู้ยื่นเอง" (ข้ามเฉพาะตอนยื่นจริง)
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array{steps: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>, unresolved: bool}
     */
    public function resolveForUnit(OrgUnit $unit, array $steps, ?string $on = null): array
    {
        return $this->resolveFrom($unit->loadMissing('type'), null, $steps, Carbon::parse($on ?? today())->toDateString());
    }

    /**
     * ขั้นมาตรฐานตามระดับ: หัวหน้าหน่วยระดับที่ต่ำกว่าหรือเท่ากับระดับของหน่วย ไล่ขึ้นถึง L1 (`workflow.default_chain_levels`)
     *
     * @return array<int, array<string, mixed>>
     */
    public static function defaultStepsFor(OrgUnit $unit): array
    {
        $level = (int) $unit->loadMissing('type')->type->level;
        $levels = array_values(array_filter((array) config('workflow.default_chain_levels', [4, 3, 2, 1]), fn ($l) => (int) $l <= $level));

        return array_map(fn ($l) => ['resolver' => 'head_of_level', 'level' => (int) $l], $levels);
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array{steps: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>, unresolved: bool}
     */
    private function resolveFrom(?OrgUnit $home, ?int $requesterPersonnelId, array $steps, string $date): array
    {
        $resolved = [];
        $skipped = [];

        foreach (array_values($steps) as $index => $step) {
            $candidates = $this->candidates($step, $home, $date);
            $eligible = array_values(array_filter($candidates, fn (array $c) => $c['personnel_id'] !== $requesterPersonnelId));

            if ($eligible === []) {
                $skipped[] = ['index' => $index, 'step' => $step,
                    'reason' => $candidates === [] ? 'ไม่พบผู้ดำรงตำแหน่ง' : 'ผู้อนุมัติคือผู้ยื่นเอง'];
                if (($step['skip_policy'] ?? 'next') === 'queue') {
                    return ['steps' => $resolved, 'skipped' => $skipped, 'unresolved' => true];
                }

                continue;
            }

            $entry = [
                'index' => $index,
                'resolver' => $step['resolver'],
                'approvers' => $eligible,
                'positions' => array_map(fn ($c) => $c['position'], $eligible),
                'merged_steps' => [$index],
            ];

            // ขั้นติดกันที่ได้ผู้อนุมัติชุดเดียวกัน (คนเดียวกัน) รวมเป็นขั้นเดียว
            $last = end($resolved);
            if ($last !== false && $this->samePeople($last['approvers'], $eligible)) {
                $key = array_key_last($resolved);
                $resolved[$key]['merged_steps'][] = $index;
                $resolved[$key]['positions'] = array_values(array_unique(array_merge($resolved[$key]['positions'], $entry['positions'])));
                $resolved[$key]['approvers'] = array_merge($resolved[$key]['approvers'], $eligible);

                continue;
            }

            $resolved[] = $entry;
        }

        return ['steps' => $resolved, 'skipped' => $skipped, 'unresolved' => $resolved === []];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<int, array{personnel_id: int, user_id: ?int, assignment_id: ?int, acting: bool, position: string}>
     */
    private function candidates(array $step, ?OrgUnit $home, string $date): array
    {
        return match ($step['resolver'] ?? null) {
            'head_of_level' => $this->headOfLevel((int) $step['level'], $home, $date),
            'functional_template' => $this->functionalTemplate((string) $step['template'], $date),
            'specific_assignment' => $this->specificAssignment((string) $step['assignment_uuid'], $date),
            default => throw new InvalidArgumentException('ไม่รู้จัก resolver '.($step['resolver'] ?? '(ว่าง)')),
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function headOfLevel(int $level, $home, string $date): array
    {
        if ($home === null) {
            return [];
        }
        $unit = $this->tree->nearestOfLevel($home, $level);
        $head = $unit ? $this->tree->headOf($unit, $date) : null;

        return $head ? [$this->fromAssignment($head)] : [];
    }

    /** @return array<int, array<string, mixed>> */
    private function functionalTemplate(string $templateCode, string $date): array
    {
        return RoleGrant::with(['user', 'template'])
            ->whereHas('template', fn ($q) => $q->where('code', $templateCode)->whereNull('retired_at'))
            ->whereNull('ended_at')
            ->whereDate('effective_from', '<=', $date)
            ->whereDate('expires_at', '>=', $date)
            ->get()
            ->filter(fn (RoleGrant $g) => $g->user?->isActive() && $g->user->personnel_id !== null)
            ->map(fn (RoleGrant $g) => [
                'personnel_id' => $g->user->personnel_id,
                'user_id' => $g->user_id,
                'assignment_id' => null,
                'acting' => false,
                'position' => $g->template->name,
            ])
            ->unique('personnel_id')->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function specificAssignment(string $uuid, string $date): array
    {
        $assignment = Assignment::where('uuid', $uuid)->effectiveOn($date)->first();

        return $assignment ? [$this->fromAssignment($assignment)] : [];
    }

    /** @return array<string, mixed> */
    private function fromAssignment(Assignment $assignment): array
    {
        $assignment->loadMissing(['position', 'orgUnit']);

        return [
            'personnel_id' => $assignment->personnel_id,
            'user_id' => User::where('personnel_id', $assignment->personnel_id)->value('id'),
            'assignment_id' => $assignment->id,
            'acting' => $assignment->kind === Assignment::KIND_ACTING,
            'position' => ($assignment->kind === Assignment::KIND_ACTING ? 'รักษาการในตำแหน่ง' : '').$assignment->position->name.$this->unitSuffix($assignment),
        ];
    }

    private function unitSuffix(Assignment $assignment): string
    {
        return ' '.$assignment->orgUnit->name;
    }

    /**
     * @param  array<int, array<string, mixed>>  $a
     * @param  array<int, array<string, mixed>>  $b
     */
    private function samePeople(array $a, array $b): bool
    {
        $ids = fn (array $list) => collect($list)->pluck('personnel_id')->unique()->sort()->values()->all();

        return $ids($a) === $ids($b);
    }
}
