<?php

namespace App\Console\Commands;

use App\Models\ApprovalStep;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Workflow\ApproverResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * ตั้งสายอนุมัติการลามาตรฐานตามลำดับอำนาจของวิทยาลัย (REDESIGN-2569 เฟส 3 · D-01 เจ้าของระบบ 18/10/2569)
 *
 *   ผู้อำนวยการ (อำนาจสูงสุด) → รองผู้อำนวยการ → หัวหน้างาน / หัวหน้าแผนก
 *
 * จึงเรียงขั้นตามแผนผังขั้นตอนการลา: งานบุคลากรตรวจสอบและลงทะเบียนคุม → หัวหน้างาน/แผนกต้นสังกัด
 * → รองผู้อำนวยการที่ดูแลฝ่ายนั้น → ผู้อำนวยการ — ผู้อนุมัติทุกขั้นหาจาก org_unit_managers ณ วันที่ยื่น
 * ไม่ใช่จากชื่อบทบาท (role ในขั้นเป็นเพียงทางถอยเมื่อยังไม่ได้กรอกหัวหน้าหน่วยงาน)
 *
 * รายชื่อหัวหน้าหน่วยงานจะนำเข้าภายหลัง จึงต้องรัน --check ให้ผ่านก่อน คำสั่งไม่เปลี่ยนสายถ้ายังมีปัญหา
 * (เว้นแต่ใส่ --force) · ใบลาที่ยื่นไปแล้วไม่กระทบ เพราะผนึกขั้นตอนไว้ในใบลาตอนยื่น
 */
class ApplyStandardLeaveChain extends Command
{
    protected $signature = 'leave:apply-standard-chain
        {--check : ตรวจอย่างเดียว ไม่เปลี่ยนสายอนุมัติ}
        {--force : ตั้งสายแม้ยังมีหน่วยงานที่ไม่มีหัวหน้า}';

    protected $description = 'ตั้งสายอนุมัติการลา: งานบุคลากร → หัวหน้างาน/แผนก → รองผู้อำนวยการ → ผู้อำนวยการ';

    public function handle(ApproverResolverService $resolver, AuditLogger $audit): int
    {
        $steps = $this->standardSteps();
        [$errors, $warnings] = $this->problems($resolver, $steps);

        if ($errors === [] && $warnings === []) {
            $this->info('ตรวจแล้ว: ทุกหน่วยงานมีหัวหน้า และบุคลากรทุกคนหาผู้อนุมัติได้ครบทุกขั้น');
        }
        if ($errors !== []) {
            $this->error('ต้องแก้ก่อนใช้สายนี้ '.count($errors).' รายการ');
            $this->table(['ประเภท', 'รายละเอียด'], $errors);
        }
        if ($warnings !== []) {
            // ไม่ขวางการตั้งสาย แต่ต้องให้ผู้มีอำนาจพิจารณา เช่น ใบลาของผู้อำนวยการเอง (D-01)
            $this->warn('ข้อสังเกต '.count($warnings).' รายการ');
            $this->table(['ประเภท', 'รายละเอียด'], $warnings);
        }

        if ($this->option('check')) {
            return $errors === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($errors !== [] && ! $this->option('force')) {
            $this->error('ยังไม่เปลี่ยนสายอนุมัติ ให้กรอกหัวหน้าหน่วยงานให้ครบก่อน หรือใส่ --force ถ้าตั้งใจ');

            return self::FAILURE;
        }

        DB::transaction(function () use ($steps, $audit) {
            $before = ApprovalStep::chain(ApprovalStep::CHAIN_LEAVE)->orderBy('step_order')->get()->toArray();
            ApprovalStep::chain(ApprovalStep::CHAIN_LEAVE)->delete();
            foreach ($steps as $step) {
                ApprovalStep::create($step);
            }
            $audit->log('approval_chain.standard_applied', 'approval_chain', ApprovalStep::CHAIN_LEAVE, ['steps' => $before], ['steps' => $steps],
                'ตั้งสายอนุมัติมาตรฐานตามลำดับอำนาจ ผอ. → รองผู้อำนวยการ → หัวหน้างาน/แผนก');
        });

        $this->info('ตั้งสายอนุมัติการลามาตรฐานแล้ว '.count($steps).' ขั้น — มีผลกับใบลาที่ยื่นหลังจากนี้');

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    public function standardSteps(): array
    {
        $role = fn (array $names) => Role::whereIn('name', $names)->value('id');

        return [
            ['chain' => ApprovalStep::CHAIN_LEAVE, 'step_order' => 1, 'name' => 'ตรวจสอบและลงทะเบียนคุมวันลาโดยงานบุคลากร',
                'approver_type' => ApprovalStep::RESOLVER_HR_OFFICER, 'role_id' => $role(['HR Head']), 'allow_self_approval' => true],
            ['chain' => ApprovalStep::CHAIN_LEAVE, 'step_order' => 2, 'name' => 'ความเห็นหัวหน้างาน / หัวหน้าแผนกวิชา',
                'approver_type' => ApprovalStep::RESOLVER_DEPARTMENT_HEAD, 'role_id' => null, 'allow_self_approval' => true],
            ['chain' => ApprovalStep::CHAIN_LEAVE, 'step_order' => 3, 'name' => 'ความเห็นรองผู้อำนวยการ',
                'approver_type' => ApprovalStep::RESOLVER_DIVISION_DIRECTOR, 'role_id' => $role(['Deputy Director']), 'allow_self_approval' => true],
            ['chain' => ApprovalStep::CHAIN_LEAVE, 'step_order' => 4, 'name' => 'พิจารณาอนุญาตโดยผู้อำนวยการ',
                'approver_type' => ApprovalStep::RESOLVER_DIRECTOR, 'role_id' => $role(['Director']), 'allow_self_approval' => true],
        ];
    }

    /**
     * errors = ทำให้ใบลาค้างหรือไปผิดคน (ขวางการตั้งสาย) · warnings = ต้องให้ผู้มีอำนาจพิจารณา
     *
     * @return array{0: list<array{0: string, 1: string}>, 1: list<array{0: string, 1: string}>}
     */
    private function problems(ApproverResolverService $resolver, array $steps): array
    {
        $problems = [];
        $warnings = [];
        $today = today()->toDateString();
        $managed = DB::table('org_unit_managers')
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->pluck('org_unit_id')->unique();

        foreach (OrgUnit::where('is_active', true)->orderBy('id')->get() as $unit) {
            if (! $managed->contains($unit->id)) {
                $problems[] = ['หน่วยงานไม่มีหัวหน้า', $unit->name];
            }
        }

        // ผู้อำนวยการ (หัวหน้าหน่วยงานสูงสุด) ไม่มีรองผู้อำนวยการอยู่เหนือขึ้นไป — ใบลาของผู้อำนวยการต้องไปตามตารางอำนาจ (D-01)
        $rootId = DB::table('org_units')->whereNull('parent_id')->value('id');
        $directorIds = $rootId ? DB::table('org_unit_managers')->where('org_unit_id', $rootId)
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all() : [];

        User::where('is_active', true)->orderBy('id')->each(function (User $user) use ($resolver, $steps, $today, $directorIds, &$problems, &$warnings) {
            $previous = null;
            foreach ($steps as $step) {
                $ids = $resolver->resolveEligibleApproverIds($user, $step, $today);
                sort($ids);
                if ($ids === [] && in_array($user->id, $directorIds, true)) {
                    $warnings[] = ['ใบลาของผู้อำนวยการ', "{$user->name}: ขั้น {$step['name']} ไม่มีผู้อนุมัติ ต้องกำหนดผู้มีอำนาจในตารางอำนาจ (D-01)"];
                } elseif ($ids === []) {
                    $problems[] = ['หาผู้อนุมัติไม่พบ', "{$user->name}: {$step['name']}"];
                } elseif ($ids === $previous) {
                    $warnings[] = ['ผู้อนุมัติซ้ำกับขั้นก่อนหน้า', "{$user->name}: {$step['name']}"];
                }
                $previous = $ids;
            }
            if ($previous === [$user->id]) {
                $warnings[] = ['อนุมัติใบลาของตนเองในขั้นสุดท้าย', "{$user->name}: ต้องกำหนดผู้มีอำนาจเหนือขึ้นไปในตารางอำนาจ (D-01)"];
            }
        });

        return [$problems, $warnings];
    }
}
