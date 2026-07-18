<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Department;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

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
            Department::updateOrCreate(
                ['name' => $deptName],
                ['is_active' => true]
            );
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
            'post_office_branch' => 'เขตดอนเมือง',
            'postal_code' => '10210',
            'water_source' => 'การประปานครหลวง',
            'internet_hours' => '56',
            'teaching_computers' => '220',
            'admin_computers' => '40',
            'electricity_system' => 'มีไฟฟ้าจากหน่วยงานรัฐ-รัฐวิสาหกิจ',
            'telephone_system' => 'มีโทรศัพท์พื้นฐาน',
            'internet_system' => 'มีการเชื่อมต่ออินเตอร์เน็ตด้วย ADSL (Broadband)',
        ];

        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
        }
    }
}
