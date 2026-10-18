<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->integer('max_days')->nullable()->default(null)->change();
        });

        $now = now();
        $types = [
            ['name' => 'ลาป่วย', 'description' => 'ลาตามอาการป่วย การรับเงินเดือนปกติไม่เกิน 60 วันทำการ และอาจได้รับอนุมัติต่อได้อีกไม่เกิน 60 วันทำการ', 'max_days' => 120, 'requires_doc' => 1, 'allow_past_dates' => 1, 'notice_period_days' => 0, 'exclude_holidays' => 1, 'code_prefix' => 'ลป.'],
            ['name' => 'ลาคลอดบุตร', 'description' => 'ลาได้ครั้งหนึ่งไม่เกิน 90 วัน โดยนับวันหยุดราชการรวมด้วย', 'max_days' => 90, 'requires_doc' => 0, 'allow_past_dates' => 1, 'notice_period_days' => 0, 'exclude_holidays' => 0, 'code_prefix' => 'ลค.'],
            ['name' => 'ลาไปช่วยเหลือภริยาที่คลอดบุตร', 'description' => 'ลาภายใน 90 วันนับแต่วันคลอด ครั้งหนึ่งติดต่อกันไม่เกิน 15 วันทำการ', 'max_days' => 15, 'requires_doc' => 1, 'allow_past_dates' => 0, 'notice_period_days' => 0, 'exclude_holidays' => 1, 'code_prefix' => 'ลช.'],
            ['name' => 'ลากิจส่วนตัว', 'description' => 'ลากิจส่วนตัวโดยได้รับเงินเดือนไม่เกิน 45 วันทำการต่อปี; กรณีเลี้ยงดูบุตรต่อจากลาคลอดลาได้ไม่เกิน 150 วันทำการโดยไม่ได้รับเงินเดือน', 'max_days' => 45, 'requires_doc' => 0, 'allow_past_dates' => 0, 'notice_period_days' => 0, 'exclude_holidays' => 1, 'code_prefix' => 'ลก.'],
            ['name' => 'ลาพักผ่อน', 'description' => 'สิทธิปีละ 10 วันทำการ สะสมรวมปีปัจจุบันได้ไม่เกิน 20 วัน หรือ 30 วันเมื่อรับราชการติดต่อกันไม่น้อยกว่า 10 ปี', 'max_days' => 10, 'requires_doc' => 0, 'allow_past_dates' => 0, 'notice_period_days' => 0, 'exclude_holidays' => 1, 'code_prefix' => 'ลพ.', 'is_carry_forward' => 1, 'max_carry_forward' => 20],
            ['name' => 'ลาอุปสมบท', 'description' => 'ประเภทร่วมกับการลาไปประกอบพิธีฮัจญ์ ลาได้ครั้งหนึ่งไม่เกิน 120 วัน และต้องยื่นล่วงหน้าไม่น้อยกว่า 60 วัน', 'max_days' => 120, 'requires_doc' => 1, 'allow_past_dates' => 0, 'notice_period_days' => 60, 'exclude_holidays' => 0, 'code_prefix' => 'ลอ.'],
            ['name' => 'ลาไปประกอบพิธีฮัจญ์', 'description' => 'ประเภทร่วมกับการลาอุปสมบท ลาได้ครั้งหนึ่งไม่เกิน 120 วัน และต้องยื่นล่วงหน้าไม่น้อยกว่า 60 วัน', 'max_days' => 120, 'requires_doc' => 1, 'allow_past_dates' => 0, 'notice_period_days' => 60, 'exclude_holidays' => 0, 'code_prefix' => 'ลฮ.'],
            ['name' => 'ลาเข้ารับการตรวจเลือกหรือเข้ารับการเตรียมพล', 'description' => 'ลาได้ตามระยะเวลาที่กำหนดในหมายเรียก จึงไม่มีเพดานวันตายตัว', 'max_days' => null, 'requires_doc' => 1, 'allow_past_dates' => 0, 'notice_period_days' => 0, 'exclude_holidays' => 0, 'code_prefix' => 'ลท.'],
            ['name' => 'ลาไปศึกษาต่อ ดูงาน ฝึกอบรม', 'description' => 'รวมการศึกษา ฝึกอบรม ปฏิบัติการวิจัย หรือดูงาน ระยะเวลาเป็นไปตามหลักสูตรหรือโครงการที่ได้รับอนุมัติ', 'max_days' => null, 'requires_doc' => 1, 'allow_past_dates' => 0, 'notice_period_days' => 30, 'exclude_holidays' => 0, 'code_prefix' => 'ลศ.'],
            ['name' => 'ลาไปปฏิบัติงานในองค์การระหว่างประเทศ', 'description' => 'ลาได้ตามหลักเกณฑ์ที่กำหนด การลาประเภทที่ 1 มีระยะเวลาไม่เกิน 1 ปี', 'max_days' => 365, 'requires_doc' => 1, 'allow_past_dates' => 0, 'notice_period_days' => 30, 'exclude_holidays' => 0, 'code_prefix' => 'ลร.'],
            ['name' => 'ลาติดตามคู่สมรส', 'description' => 'อนุญาตได้ไม่เกิน 2 ปี และกรณีจำเป็นต่อได้อีก 2 ปี รวมไม่เกิน 4 ปี โดยไม่ได้รับเงินเดือน', 'max_days' => 1460, 'requires_doc' => 1, 'allow_past_dates' => 0, 'notice_period_days' => 30, 'exclude_holidays' => 0, 'code_prefix' => 'ลต.'],
            ['name' => 'ลาไปฟื้นฟูสมรรถภาพด้านอาชีพ', 'description' => 'ลาได้ตามระยะเวลาที่กำหนดในหลักสูตร ครั้งหนึ่งไม่เกิน 12 เดือน', 'max_days' => 365, 'requires_doc' => 1, 'allow_past_dates' => 0, 'notice_period_days' => 0, 'exclude_holidays' => 0, 'code_prefix' => 'ลฟ.'],
        ];

        foreach ($types as $type) {
            DB::table('leave_types')->updateOrInsert(
                ['name' => $type['name']],
                $type + ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        DB::table('leave_types')->whereNull('max_days')->update(['max_days' => 30]);

        Schema::table('leave_types', function (Blueprint $table) {
            $table->integer('max_days')->default(30)->nullable(false)->change();
        });
    }
};
