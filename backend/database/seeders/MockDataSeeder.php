<?php

namespace Database\Seeders;

use App\Models\OrgUnit;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MockDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. จำลองข้อมูลแผนกวิชา
        $departments = [
            'สมรรถนะแกนกลาง',
            'การบัญชี',
            'การตลาด',
            'คอมพิวเตอร์ธุรกิจ',
            'ช่างยนต์',
            'ช่างกลโรงงาน',
            'ช่างเทคนิคพื้นฐาน',
            'ช่างเทคนิคอุตสาหกรรม',
            'ช่างเชื่อมโลหะ',
            'ช่างไฟฟ้ากำลัง',
        ];

        foreach ($departments as $deptName) {
            $this->seedOrgUnit($deptName);
        }

        // 2. จำลองข้อมูลการตั้งค่าสถานศึกษา
        $settings = [
            'institution_code' => '1310366101',
            'institution_type' => 'วิทยาลัยเทคนิค',
            'institution_name' => 'วิทยาลัยเทคนิคดอนเมือง',
            'vocational_province' => 'กรุงเทพมหานคร',
            'vocational_institute' => 'สถาบันการอาชีวศึกษากรุงเทพมหานคร',
            'director_name' => 'นายธราเชฏฐ์ สุคนธ์',
            'deputy_acting_name' => '',
            'deputy_academic_name' => 'นายสุริยา เผด็จศึก',
            'deputy_development_name' => 'นายปิยพงศ์ วชิรมนตรี',
            'deputy_admin_name' => 'นางพรรณสิริ ขุนพรัตน์',
            'deputy_planning_name' => 'นางอรุณี มีสัจ',
            'head_hr_name' => 'นายสมปอง หัวหน้าบุคลากร',
            'head_registrar_name' => 'นายจักรภพ โตนันทวณิช',
            'head_finance_name' => 'นางรุ่งนภา โคตรเวียง',
            'head_curriculum_name' => 'นางสาวอัญชลี จันทร์มา',
            'head_evaluation_name' => 'นางสาวเรวดี อะมินรัมย์',
            'head_guidance_name' => 'นางสาวกมลาศิริ ภูเวช',
            'head_discipline_name' => 'ว่าที่ร้อยตรีพงศธร สุริยวรากุล',
            'head_activity_name' => 'นายพงศ์ภัค พรหมประเสริฐ',
            'head_data_center_name' => 'นายศตวรรษ อัศชาด',
            'short_name_th' => 'วท.ดอนเมือง',
            'short_name_en' => 'DMTC',
            'full_name_en' => 'Donmueang Technical College',
            'school_type' => 'ในระบบ',
            'teaching_language' => 'ภาษาไทย',
            'document_number_out' => 'ศธ ๐๖๓๓.๓',
            'founding_date' => '1979-01-01',
            'start_time' => '08:30',
            'end_time' => '16:30',
            'postal_code' => '10210',
        ];

        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
        }
    }

    /** แผนกวิชาเป็นหน่วยงานในผังจริง แขวนใต้ยอดผัง */
    private function seedOrgUnit(string $name): OrgUnit
    {
        $root = OrgUnit::whereNull('parent_id')->first();

        return OrgUnit::firstOrCreate(['name' => $name], [
            'uuid' => (string) Str::uuid(),
            'parent_id' => $root?->id,
            'code' => 'ORG-'.Str::upper(Str::random(6)),
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);
    }
}
