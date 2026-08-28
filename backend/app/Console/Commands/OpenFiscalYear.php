<?php

namespace App\Console\Commands;

use App\Models\FiscalYear;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\User;
use App\Services\LeavePolicyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * เปิดรอบปีงบประมาณใหม่ — เลื่อนปีปัจจุบัน และตั้งสิทธิ์วันลาของปีใหม่ให้ทุกคน
 *
 * ก่อนหน้านี้ไม่มีอะไรทำสองอย่างนี้ให้เลย:
 *
 *   1. `FiscalYear::current()` อ่านธง `is_current` ซึ่งมีแค่ seeder ตอนติดตั้งกับหน้าตั้งค่า
 *      ที่ผู้ดูแลต้องกดเองเป็นคนเขียน หลัง 30 ก.ย. ระบบจึงยังคิดว่าเป็นปีงบประมาณเดิมตลอดไป
 *      ทุกอย่างที่อิงปีงบประมาณค้างตามไปด้วย — ยอดวันลา เลขที่หนังสือ ตัวกรองรายงาน
 *
 *   2. แถวใน `leave_balances` ถูกสร้างสองทางเท่านั้น คือตอนเพิ่มบุคลากรใหม่ (เฉพาะปีปัจจุบัน)
 *      กับตอนทบวันลาข้ามปี ซึ่งครอบเฉพาะประเภทที่ทบได้ พอขึ้นรอบใหม่ประเภทที่เหลือจึงไม่มีแถว
 *      และหน้า "วันลาคงเหลือ" ว่างเปล่า ผู้ใช้เห็นว่าตนไม่มีสิทธิ์ลาเลย
 *
 * ตั้งเวลารันเช้าวันที่ 1 ต.ค. **ก่อน** คำสั่งทบวันลา เพราะการทบต้องเขียนทับ `carried_forward`
 * ของแถวที่คำสั่งนี้สร้างไว้
 *
 * รันซ้ำได้ (idempotent) — ไม่แตะ used/reserved/carried_forward ของแถวที่มีอยู่แล้ว
 */
class OpenFiscalYear extends Command
{
    protected $signature = 'leave:open-fiscal-year
        {--year= : ปีงบประมาณ พ.ศ. ที่ต้องการเปิด เช่น 2570 (ไม่ระบุ = รอบที่ครอบวันนี้)}
        {--dry-run : แสดงผลที่จะเกิดขึ้นโดยไม่บันทึก}';

    protected $description = 'เลื่อนปีงบประมาณปัจจุบันและสร้างสิทธิ์วันลาของปีใหม่ให้บุคลากรทุกคน';

    public function handle(LeavePolicyService $policies): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = today();

        $fiscalYear = $this->option('year')
            ? FiscalYear::where('year', (int) $this->option('year'))->first()
            : FiscalYear::whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->first();

        if (! $fiscalYear) {
            $this->error('ไม่พบรอบปีงบประมาณที่ต้องการเปิด — ตั้งค่าปีงบประมาณที่เมนูตั้งค่าก่อน');

            return self::FAILURE;
        }

        $this->info("เปิดรอบปีงบประมาณ {$fiscalYear->year}  ({$fiscalYear->start_date} – {$fiscalYear->end_date})");

        $yearKey = $policies->fiscalYearNumber($fiscalYear, $today);

        if (! $dryRun && ! $fiscalYear->is_current) {
            DB::transaction(function () use ($fiscalYear) {
                FiscalYear::where('is_current', true)->update(['is_current' => false]);
                $fiscalYear->update(['is_current' => true]);
            });
            $this->line('  เลื่อนปีงบประมาณปัจจุบันแล้ว');
        } elseif ($fiscalYear->is_current) {
            $this->line('  ปีงบประมาณนี้เป็นปีปัจจุบันอยู่แล้ว');
        }

        $created = $this->provisionBalances($yearKey, $dryRun);

        $this->info($dryRun
            ? "จะสร้างสิทธิ์วันลา {$created} รายการ (โหมด dry-run ยังไม่บันทึก)"
            : "สร้างสิทธิ์วันลาของปีใหม่ {$created} รายการ");

        return self::SUCCESS;
    }

    /**
     * สร้างแถวโควตาของปีที่กำหนดให้บุคลากรที่ยังปฏิบัติงานอยู่ทุกคน
     *
     * โควตามาจาก `leave_policies` ตามประเภทบุคลากรของแต่ละคน ไม่ใช่ `leave_types.max_days`
     * เพราะนโยบายคือแหล่งเดียวที่ระบบใช้ตัดสินสิทธิ์จริง
     *
     * ประเภทที่นับตามปีปฏิทินถูกข้ามไป — แถวของนโยบายชนิดนั้นคีย์ด้วยปีปฏิทิน
     * ไม่ใช่ปีงบประมาณ จึงไม่ใช่งานของคำสั่งนี้
     */
    private function provisionBalances(int $yearKey, bool $dryRun): int
    {
        $policiesByType = LeavePolicy::where('is_active', true)
            ->where('period_basis', 'fiscal_year')
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('personnel_type_id');

        if ($policiesByType->isEmpty()) {
            $this->warn('ไม่มีนโยบายสิทธิการลาที่เปิดใช้งาน');

            return 0;
        }

        $created = 0;

        User::where('is_active', true)
            ->whereNotNull('personnel_type_id')
            ->select('id', 'personnel_type_id')
            ->chunkById(500, function ($users) use ($policiesByType, $yearKey, $dryRun, &$created) {
                foreach ($users as $user) {
                    $applicable = $policiesByType->get($user->personnel_type_id);

                    if ($applicable === null) {
                        continue;
                    }

                    // นโยบายที่มีผลล่าสุดของแต่ละประเภทการลา
                    foreach ($applicable->groupBy('leave_type_id') as $leaveTypeId => $candidates) {
                        $policy = $candidates->first();

                        $exists = LeaveBalance::where('user_id', $user->id)
                            ->where('leave_type_id', $leaveTypeId)
                            ->where('year', $yearKey)
                            ->exists();

                        if ($exists) {
                            continue;
                        }

                        $created++;

                        if ($dryRun) {
                            continue;
                        }

                        LeaveBalance::create([
                            'user_id' => $user->id,
                            'leave_type_id' => $leaveTypeId,
                            'year' => $yearKey,
                            'quota' => $policy->annual_quota ?? 0,
                            'used' => 0,
                            'reserved' => 0,
                            'carried_forward' => 0,
                            'leave_policy_id' => $policy->id,
                        ]);
                    }
                }
            });

        return $created;
    }
}
