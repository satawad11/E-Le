<?php

namespace App\Console\Commands;

use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * ตรวจว่าทุกประเภทบุคลากรที่เปิดใช้งานมีนโยบายการลาครบทุกประเภทการลา ณ วันที่ระบุ (REDESIGN-2569 เฟส 1)
 *
 * ช่องที่ขาดอันตรายเพราะ `LeavePolicyService::resolve()` จะสร้างนโยบายให้เองจาก `leave_types.max_days`
 * ซึ่งเป็นค่าระดับข้าราชการ — ลูกจ้างหรือครูจ้างจึงอาจได้สิทธิ์ลาเกินจริงโดยไม่มีใครตั้งใจ
 *
 * อ่านอย่างเดียว ไม่แก้ข้อมูล · คืนค่าล้มเหลวเมื่อพบช่องว่าง เพื่อใช้เป็นเกณฑ์ก่อนผูกบุคลากรจริง
 */
class LeavePolicyCoverage extends Command
{
    protected $signature = 'leave:policy-coverage {--date= : วันที่ที่ตรวจ (YYYY-MM-DD) ค่าเริ่มต้นคือวันนี้}';

    protected $description = 'ตรวจว่าประเภทบุคลากรทุกประเภทมีนโยบายการลาครบทุกประเภทการลา';

    public function handle(): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date'))->startOfDay() : today();

        $personnelTypes = PersonnelType::where('is_active', true)->orderBy('id')->get();
        $leaveTypes = LeaveType::orderBy('id')->get();

        $covered = LeavePolicy::where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->get(['personnel_type_id', 'leave_type_id'])
            ->map(fn ($p) => $p->personnel_type_id.'|'.$p->leave_type_id)
            ->flip();

        $missing = [];
        foreach ($personnelTypes as $personnelType) {
            foreach ($leaveTypes as $leaveType) {
                if (! $covered->has($personnelType->id.'|'.$leaveType->id)) {
                    $missing[] = [$personnelType->name, $leaveType->name];
                }
            }
        }

        $thaiDate = $date->format('j/n/').($date->year + 543);

        if ($missing === []) {
            $this->info("นโยบายการลาครบทุกประเภทบุคลากร ณ วันที่ {$thaiDate}");

            return self::SUCCESS;
        }

        $this->error('พบประเภทบุคลากรที่ยังไม่มีนโยบายการลา '.count($missing)." รายการ ณ วันที่ {$thaiDate}");
        $this->table(['ประเภทบุคลากร', 'ประเภทการลา'], $missing);
        $this->line('ให้สร้างนโยบายของช่องเหล่านี้ก่อนผูกบุคลากร ประเภทการลาที่ไม่มีสิทธิ์ให้สร้างแถวเปิดใช้งานโควตา 0');

        return self::FAILURE;
    }
}
