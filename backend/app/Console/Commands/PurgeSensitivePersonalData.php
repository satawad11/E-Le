<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ลบข้อมูลอ่อนไหวตาม ม.26 พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล ที่ระบบเก็บไว้แต่ไม่ได้นำไปใช้
 * (ศาสนา และ เชื้อชาติ) — ระบบหยุดรับค่าใหม่แล้ว คำสั่งนี้ล้างของเดิมที่ค้างอยู่
 *
 * ลบแล้วกู้คืนไม่ได้ จึงต้องสั่ง --force เท่านั้นถึงจะเขียนจริง
 */
class PurgeSensitivePersonalData extends Command
{
    protected $signature = 'pdpa:purge-sensitive-fields {--force : ยืนยันการลบจริง (ค่าเริ่มต้นเป็นการแสดงผลอย่างเดียว)}';

    protected $description = 'ล้างข้อมูลศาสนาและเชื้อชาติที่ค้างอยู่ในทะเบียนประวัติตามหลัก PDPA';

    private const SENSITIVE_COLUMNS = ['religion' => 'ศาสนา', 'ethnicity' => 'เชื้อชาติ'];

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $rows = [];
        $total = 0;

        foreach (self::SENSITIVE_COLUMNS as $column => $label) {
            if (! Schema::hasColumn('user_profiles', $column)) {
                continue;
            }

            $count = DB::table('user_profiles')->whereNotNull($column)->where($column, '!=', '')->count();
            $rows[] = [$label, $column, $count];
            $total += $count;

            if ($force && $count > 0) {
                DB::table('user_profiles')->update([$column => null]);
            }
        }

        $this->table(['ข้อมูล', 'คอลัมน์', 'จำนวนระเบียนที่มีค่า'], $rows);

        if (! $force) {
            $this->warn("พบข้อมูลอ่อนไหวรวม {$total} ค่า — ยังไม่ได้ลบ");
            $this->line('สั่ง php artisan pdpa:purge-sensitive-fields --force เพื่อลบจริง (ลบแล้วกู้คืนไม่ได้)');

            return self::SUCCESS;
        }

        $this->info("ล้างข้อมูลอ่อนไหวเรียบร้อย {$total} ค่า");

        return self::SUCCESS;
    }
}
