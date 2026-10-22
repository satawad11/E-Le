<?php

namespace App\Services\Access;

use App\Authz\Grant;
use App\Authz\Manifest\ModuleRegistry;
use App\Authz\PermissionEngine;
use App\Models\Assignment;
use App\Models\User;

/**
 * ใครต้องใช้ MFA (CORE-03 นโยบาย)
 *
 * - ผู้ดำรงตำแหน่งบริหารหรือรักษาการในหน่วยระดับ `auth.mfa.levels` (L1–L3) ณ วันนี้
 * - ผู้ถือชุดสิทธิ์ที่ manifest ประกาศ `'mfa' => true` (งานบุคคล ผู้ดูแลระบบ) จากแหล่งใดก็ได้ที่ engine นับว่ามีผล
 * - บัญชี break-glass
 *
 * ตัดสินจากข้อมูลบุคลากรและสิทธิ์ที่มีผล ไม่ใช่ชื่อ role — คนที่ได้รับแต่งตั้งเป็นหัวหน้างานวันนี้ต้องใช้ MFA ตั้งแต่ครั้งถัดไป
 */
class MfaPolicy
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly PermissionEngine $engine,
    ) {}

    public function requires(User $user): bool
    {
        return $this->reasons($user) !== [];
    }

    /** @return array<int, string> เหตุที่ต้องใช้ MFA (แสดงในหน้าบัญชีผู้ใช้และหน้าเข้าสู่ระบบ) */
    public function reasons(User $user): array
    {
        $reasons = [];

        if ($user->is_break_glass) {
            $reasons[] = 'บัญชีฉุกเฉิน (break-glass)';
        }

        if ($user->personnel_id !== null) {
            $levels = array_map('intval', (array) config('auth.mfa.levels', [1, 2, 3]));
            $assignments = Assignment::with(['orgUnit.type', 'position'])
                ->where('personnel_id', $user->personnel_id)
                ->whereIn('kind', [Assignment::KIND_MANAGEMENT, Assignment::KIND_ACTING])
                ->effectiveOn(today()->toDateString())
                ->get();

            foreach ($assignments as $assignment) {
                $level = (int) $assignment->orgUnit?->type?->level;
                if (in_array($level, $levels, true)) {
                    $prefix = $assignment->kind === Assignment::KIND_ACTING ? 'รักษาการ' : '';
                    $reasons[] = "{$prefix}{$assignment->position?->name} (L{$level})";
                }
            }
        }

        $mfaTemplates = array_keys(array_filter($this->registry->templates(), fn (array $t) => $t['mfa'] ?? false));
        if ($mfaTemplates !== [] && $user->isActive()) {
            $names = $this->engine->effective($user)
                ->filter(fn (Grant $g) => in_array($g->template, $mfaTemplates, true))
                ->map(fn (Grant $g) => 'ชุดสิทธิ์'.$this->registry->templates()[$g->template]['name'])
                ->unique()->values()->all();
            array_push($reasons, ...$names);
        }

        return array_values(array_unique($reasons));
    }
}
