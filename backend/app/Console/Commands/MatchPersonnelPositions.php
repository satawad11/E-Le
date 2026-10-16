<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCommandUsers;
use App\Models\PersonnelAssignment;
use App\Models\Position;
use App\Services\PermissionEvaluator;
use App\Services\PersonnelSyncService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * จับคู่ข้อความตำแหน่งเดิมกับทะเบียนตำแหน่ง (ORG-ACCESS-02)
 *
 * จับคู่เฉพาะชื่อที่ตรงกันทุกตัวอักษร (ตัดช่องว่างหัวท้าย) กับตำแหน่งที่ใช้งานอยู่ ไม่เดา
 * รายการที่จับคู่ไม่ได้ให้งานบุคลากรเลือกเองจากหน้าจัดการผู้ใช้
 *
 *   php artisan personnel:match-positions --dry-run
 *   php artisan personnel:match-positions --by=admin@college.ac.th
 */
class MatchPersonnelPositions extends Command
{
    use ResolvesCommandUsers;

    protected $signature = 'personnel:match-positions
        {--dry-run : แสดงผลการจับคู่โดยไม่บันทึก}
        {--by= : อีเมลหรือ UUID ของผู้ดูแลที่สั่ง (ต้องมีสิทธิ์จัดการผู้ใช้) — จำเป็นเมื่อบันทึกจริง}';

    protected $description = 'จับคู่ข้อความตำแหน่งเดิมของบุคลากรกับทะเบียนตำแหน่ง (ชื่อตรงกันทุกตัวอักษรเท่านั้น)';

    public function handle(PersonnelSyncService $personnel, PermissionEvaluator $evaluator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $actor = null;

        if (! $dryRun) {
            try {
                $actor = $this->findUser((string) $this->option('by'), 'ผู้สั่ง');
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            if (! $actor->is_active || ! $evaluator->allows($actor, 'system.user.manage')) {
                $this->error('ผู้สั่งต้องเป็นผู้ใช้ที่ใช้งานอยู่และมีสิทธิ์จัดการผู้ใช้ (system.user.manage)');

                return self::FAILURE;
            }
        }

        $catalog = Position::where('is_active', true)->get()->keyBy(fn (Position $position) => trim($position->name));

        $assignments = PersonnelAssignment::query()
            ->with('user')
            ->whereNull('effective_to')
            ->whereNull('position_id')
            ->whereNotNull('position')
            ->where('position', '!=', '')
            ->orderBy('user_id')
            ->get();

        $matched = [];
        $unmatched = [];

        foreach ($assignments as $assignment) {
            $position = $catalog->get(trim((string) $assignment->position));
            $row = [$assignment->user?->email, $assignment->user?->name, $assignment->position];

            if ($position === null) {
                $unmatched[] = $row;

                continue;
            }

            $matched[] = [...$row, $position->code];

            if (! $dryRun && $assignment->user !== null) {
                $personnel->assignCatalogPosition($assignment->user, $position, $actor->getKey());
            }
        }

        if ($matched !== []) {
            $this->info(($dryRun ? 'จะจับคู่' : 'จับคู่แล้ว').' '.count($matched).' คน');
            $this->table(['อีเมล', 'ชื่อ', 'ตำแหน่งเดิม', 'รหัสตำแหน่ง'], $matched);
        }

        if ($unmatched !== []) {
            $this->warn('จับคู่ไม่ได้ '.count($unmatched).' คน — กรุณาเลือกตำแหน่งจากหน้าจัดการผู้ใช้');
            $this->table(['อีเมล', 'ชื่อ', 'ตำแหน่งเดิม'], $unmatched);
        }

        if ($matched === [] && $unmatched === []) {
            $this->info('ไม่มีบุคลากรที่ต้องจับคู่ตำแหน่ง');
        }

        return self::SUCCESS;
    }
}
