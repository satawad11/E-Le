<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NewLeaveTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $newTypes = [
            [
                'name' => 'ลาอุปสมบท',
                'description' => 'ลาเพื่อไปอุปสมบท หรือประกอบพิธีทางศาสนา',
                'max_days' => 120,
                'requires_doc' => 1,
                'allow_past_dates' => 0,
                'notice_period_days' => 60,
                'exclude_holidays' => 0,
                'code_prefix' => 'ลอ.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'ลาไปประกอบพิธีฮัจญ์',
                'description' => 'ลาไปประกอบพิธีฮัจญ์ ณ เมืองเมกกะ ประเทศซาอุดีอาระเบีย',
                'max_days' => 120,
                'requires_doc' => 1,
                'allow_past_dates' => 0,
                'notice_period_days' => 60,
                'exclude_holidays' => 0,
                'code_prefix' => 'ลฮ.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'ลาติดตามคู่สมรส',
                'description' => 'ลาติดตามคู่สมรสไปปฏิบัติราชการหรือทำงานในต่างประเทศ',
                'max_days' => 730,
                'requires_doc' => 1,
                'allow_past_dates' => 0,
                'notice_period_days' => 30,
                'exclude_holidays' => 0,
                'code_prefix' => 'ลต.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'ลาไปศึกษาต่อ ดูงาน ฝึกอบรม',
                'description' => 'ลาไปศึกษาต่อ ดูงาน ฝึกอบรม หรือปฏิบัติการวิจัย',
                'max_days' => 1460, // e.g. 4 years max
                'requires_doc' => 1,
                'allow_past_dates' => 0,
                'notice_period_days' => 30,
                'exclude_holidays' => 0,
                'code_prefix' => 'ลศ.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($newTypes as $type) {
            DB::table('leave_types')->updateOrInsert(
                ['name' => $type['name']],
                $type
            );
        }
    }
}
