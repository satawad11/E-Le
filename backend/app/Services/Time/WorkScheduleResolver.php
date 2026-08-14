<?php

namespace App\Services\Time;

use App\Models\Time\WorkSchedule;
use App\Models\Time\WorkScheduleAssignment;
use App\Models\User;
use App\Services\OrgScopeResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * LEGACY-06 · 06-1 — หาว่าผู้ใช้คนหนึ่งใช้ตารางเวลาใดในวันหนึ่ง
 *
 * ลำดับความสำคัญ (แคบไปกว้าง):
 *   1. มอบหมายให้ผู้ใช้คนนั้นโดยตรง   ← person-specific override
 *   2. มอบหมายให้หน่วยงานของตน แล้วไล่ขึ้นไปหาหน่วยงานแม่
 *   3. มอบหมายระดับองค์กร (user_id และ org_unit_id เป็น null)
 *   4. ตารางที่ตั้ง `is_default`
 *
 * ทุกชั้นตรวจ effective date เสมอ — การแก้ตารางเวลาวันนี้จึงไม่เปลี่ยน
 * ผลการคำนวณของวันที่ผ่านมาแล้ว
 */
class WorkScheduleResolver
{
    public function __construct(private readonly OrgScopeResolver $orgScope) {}

    /** @var array<string,?WorkSchedule> cache ต่อ request */
    private array $cache = [];

    public function resolve(?User $user, CarbonInterface $date): ?WorkSchedule
    {
        $on = $date->toDateString();
        $key = ($user?->getKey() ?? 'org').'@'.$on;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->lookup($user, $on);
    }

    private function lookup(?User $user, string $on): ?WorkSchedule
    {
        if ($user !== null) {
            $personal = $this->assignmentQuery($on)
                ->where('user_id', $user->getKey())
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();

            if ($personal !== null) {
                return $this->scheduleOf($personal);
            }

            foreach ($this->orgUnitChain($user) as $orgUnitId) {
                $unitAssignment = $this->assignmentQuery($on)
                    ->where('org_unit_id', $orgUnitId)
                    ->orderByDesc('effective_from')
                    ->orderByDesc('id')
                    ->first();

                if ($unitAssignment !== null) {
                    return $this->scheduleOf($unitAssignment);
                }
            }
        }

        $organisation = $this->assignmentQuery($on)
            ->whereNull('user_id')
            ->whereNull('org_unit_id')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($organisation !== null) {
            return $this->scheduleOf($organisation);
        }

        return WorkSchedule::with('days')->where('is_default', true)->where('is_active', true)->first();
    }

    /**
     * หน่วยงานของผู้ใช้ ไล่จากหน่วยงานของตนขึ้นไปถึงราก
     *
     * ใช้ `org_units` ที่ LEGACY-03 สร้างไว้ ผ่านตัว resolver เดียวกับที่ระบบสิทธิ์ใช้
     * จึงไม่มีตรรกะการไต่ต้นไม้องค์กรซ้ำสองที่
     *
     * @return array<int,int>
     */
    private function orgUnitChain(User $user): array
    {
        $unitId = $this->orgScope->homeOrgUnitId($user);

        if ($unitId === null) {
            return [];
        }

        $chain = [];
        $guard = 0;

        while ($unitId !== null && $guard++ < 50) {
            $chain[] = $unitId;
            $unitId = DB::table('org_units')->where('id', $unitId)->value('parent_id');
            $unitId = $unitId === null ? null : (int) $unitId;

            // กันข้อมูลที่มี cycle ไม่ให้วนไม่รู้จบ
            if ($unitId !== null && in_array($unitId, $chain, true)) {
                break;
            }
        }

        return $chain;
    }

    private function assignmentQuery(string $on)
    {
        return WorkScheduleAssignment::query()->effectiveOn($on);
    }

    private function scheduleOf(WorkScheduleAssignment $assignment): ?WorkSchedule
    {
        $schedule = WorkSchedule::with('days')->find($assignment->work_schedule_id);

        return $schedule?->is_active ? $schedule : null;
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
