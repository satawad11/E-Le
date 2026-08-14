<?php

namespace App\Console\Commands;

use App\Models\Leave;
use App\Models\Workflow\WorkflowShadowComparison;
use App\Services\Workflow\LeaveWorkflowProvisioner;
use App\Services\Workflow\LeaveWorkflowShadow;
use Illuminate\Console\Command;

/**
 * LEGACY-05 · 05-3 — เทียบผล engine ใหม่กับตรรกะเดิมสำหรับใบลาทุกใบในระบบ
 *
 * ใช้ตรวจก่อนตัดสินใจ cutover โดยไม่ต้องรอให้มีการอนุมัติจริงเกิดขึ้น
 * อ่านอย่างเดียว — ไม่แก้ใบลา ไม่แก้ประวัติการอนุมัติ
 */
class WorkflowShadowReplay extends Command
{
    protected $signature = 'workflow:shadow-replay {--fresh : ล้างผลเทียบเดิมก่อนรันใหม่}';

    protected $description = 'เทียบผลของ Workflow Engine กับตรรกะเดิมสำหรับใบลาทุกใบ (shadow mode)';

    public function handle(LeaveWorkflowProvisioner $provisioner, LeaveWorkflowShadow $shadow): int
    {
        $provisioner->publishCurrentVersion();

        if ($this->option('fresh')) {
            WorkflowShadowComparison::query()->delete();
            $this->warn('ล้างผลเทียบเดิมแล้ว');
        }

        $total = 0;
        $mismatched = 0;

        /*
         * รวมใบลาที่ถูกลบแบบ soft delete ด้วย
         *
         * ฐานข้อมูลปัจจุบันมีใบลาสามใบและถูก soft delete ทั้งหมด ถ้ากรองออก
         * รายงานจะกลายเป็น "ตรงกัน 0 จาก 0 จุด" ซึ่งดูเหมือนผ่านทั้งที่ไม่ได้
         * เทียบอะไรเลย — เอาเข้ามาเทียบด้วยเพื่อให้ตัวเลขสะท้อนของจริง
         */
        Leave::withTrashed()->orderBy('id')->chunkById(100, function ($leaves) use ($shadow, &$total, &$mismatched): void {
            foreach ($leaves as $leave) {
                foreach ($shadow->compare($leave) as $comparison) {
                    $total++;

                    if (! $comparison->matched) {
                        $mismatched++;
                        $this->line(sprintf(
                            '  MISMATCH leave#%d [%s] legacy=%s engine=%s',
                            $leave->id,
                            $comparison->checkpoint,
                            json_encode($comparison->legacy_result, JSON_UNESCAPED_UNICODE),
                            json_encode($comparison->engine_result, JSON_UNESCAPED_UNICODE),
                        ));
                    }
                }
            }
        });

        $this->newLine();
        $this->info("เทียบทั้งหมด {$total} จุด · ตรงกัน ".($total - $mismatched)." · ไม่ตรง {$mismatched}");

        if ($mismatched > 0) {
            $this->error('พบผลไม่ตรงกัน — ห้าม cutover ตามกฎข้อ 9 ของ LEGACY-05');

            return self::FAILURE;
        }

        $this->info('ผลตรงกันทั้งหมด');

        return self::SUCCESS;
    }
}
