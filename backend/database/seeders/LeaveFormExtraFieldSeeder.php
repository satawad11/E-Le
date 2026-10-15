<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

/**
 * ช่องกรอกเฉพาะแบบฟอร์ม "ตั้งต้น" ของประเภทการลาที่แบบฟอร์มราชการต้องใช้
 *
 * เป็นเพียงค่าเริ่มต้นให้ใช้งานได้ทันที — ผู้ดูแลเพิ่ม/ลบ/แก้ไขได้เองจากหน้า
 * ตั้งค่าประเภทการลา โดยไม่ต้องแก้โค้ด (ห้าม hard-code นโยบายหรือแบบฟอร์ม)
 *
 * ไม่เขียนทับของเดิม: ประเภทการลาที่ตั้งช่องไว้แล้วจะถูกข้าม
 */
class LeaveFormExtraFieldSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'leave_ordination' => [
                ['key' => 'ordination_temple', 'label' => 'วัดที่อุปสมบท', 'type' => 'text', 'required' => true],
                ['key' => 'ordination_temple_address', 'label' => 'ที่ตั้งวัดที่อุปสมบท', 'type' => 'text', 'required' => false],
                ['key' => 'ordination_date', 'label' => 'วันกำหนดอุปสมบท', 'type' => 'date', 'required' => true],
                ['key' => 'retreat_temple', 'label' => 'วัดที่จำพรรษา', 'type' => 'text', 'required' => false],
                ['key' => 'retreat_temple_address', 'label' => 'ที่ตั้งวัดที่จำพรรษา', 'type' => 'text', 'required' => false],
                ['key' => 'ordained_before', 'label' => 'เคยอุปสมบทแล้วหรือไม่', 'type' => 'select', 'required' => false, 'options' => ['ยังไม่เคย', 'เคย']],
            ],
            'leave_paternity' => [
                ['key' => 'wife_name', 'label' => 'ชื่อภริยาโดยชอบด้วยกฎหมาย', 'type' => 'text', 'required' => true],
                ['key' => 'childbirth_date', 'label' => 'วันที่ภริยาคลอดบุตร', 'type' => 'date', 'required' => true],
                ['key' => 'level', 'label' => 'ระดับ/วิทยฐานะ', 'type' => 'text', 'required' => false],
            ],
        ];

        foreach ($defaults as $templateCode => $fields) {
            LeaveType::where('document_template_code', $templateCode)
                ->whereNull('extra_fields')
                ->get()
                ->each(fn (LeaveType $type) => $type->update(['extra_fields' => $fields]));
        }
    }
}
