<?php

namespace App\Console\Commands;

use App\Models\Workflow\WorkflowShadowComparison;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * LEGACY-05 · 05-3/05-4 — สรุปผล shadow mode เพื่อตัดสินใจเรื่อง cutover
 *
 * เป็นคำตอบเดียวที่ใช้ตอบว่า "cutover ได้หรือยัง" ตามกฎข้อ 9
 */
class WorkflowShadowReport extends Command
{
    protected $signature = 'workflow:shadow-report {--mismatches : แสดงรายการที่ไม่ตรงทั้งหมด}';

    protected $description = 'สรุปผลเทียบ Workflow Engine กับตรรกะเดิม และบอกว่าพร้อม cutover หรือยัง';

    public function handle(): int
    {
        /*
         * ใช้ query builder ดิบ ไม่ผ่าน Eloquent
         *
         * ถ้า select ผลรวมมาใส่ชื่อ `matched` โมเดลจะ cast เป็น boolean ตาม
         * $casts ทำให้ผลรวม 2 กลายเป็น true แล้วแสดงเป็น 1 — ตัวเลขในรายงาน
         * จะผิดโดยไม่มีอะไรฟ้อง
         */
        $rows = DB::table('workflow_shadow_comparisons')
            ->select('checkpoint', DB::raw('COUNT(*) as total'), DB::raw('SUM(CASE WHEN matched = 1 THEN 1 ELSE 0 END) as matched_count'))
            ->groupBy('checkpoint')
            ->orderBy('checkpoint')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('ยังไม่มีผลเทียบ — รัน `php artisan workflow:shadow-replay` ก่อน');

            return self::FAILURE;
        }

        $this->table(
            ['checkpoint', 'ทั้งหมด', 'ตรงกัน', 'ไม่ตรง'],
            $rows->map(fn ($row) => [
                $row->checkpoint,
                (int) $row->total,
                (int) $row->matched_count,
                (int) $row->total - (int) $row->matched_count,
            ])->all(),
        );

        $mismatches = WorkflowShadowComparison::where('matched', false)->count();
        $total = (int) $rows->sum('total');

        if ($this->option('mismatches') && $mismatches > 0) {
            $this->newLine();
            $this->error('รายการที่ไม่ตรงกัน:');

            WorkflowShadowComparison::where('matched', false)
                ->orderBy('id')
                ->chunk(100, function ($chunk): void {
                    foreach ($chunk as $row) {
                        $this->line(sprintf(
                            '  %s#%d [%s] legacy=%s engine=%s',
                            class_basename($row->subject_type),
                            $row->subject_id,
                            $row->checkpoint,
                            json_encode($row->legacy_result, JSON_UNESCAPED_UNICODE),
                            json_encode($row->engine_result, JSON_UNESCAPED_UNICODE),
                        ));
                    }
                });
        }

        $this->newLine();
        $this->info("รวม {$total} จุด · ไม่ตรง {$mismatches} จุด");

        if ($mismatches > 0) {
            $this->error('สถานะ: ยังไม่พร้อม cutover — ต้องแก้ให้ตรง 100% ก่อน (กฎข้อ 9)');

            return self::FAILURE;
        }

        $this->info('สถานะ: ผลตรงกัน 100%');
        $this->comment('การ cutover (05-4) ยังเป็นขั้นตอนแยกที่ต้องได้รับอนุมัติ และต้องเดิน shadow');
        $this->comment('ครบช่วงเวลาที่ตกลงกันบนการใช้งานจริงก่อน — ห้าม cutover จากผล replay อย่างเดียว');

        return self::SUCCESS;
    }
}
