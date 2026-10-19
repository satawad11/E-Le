<?php

namespace Database\Seeders;

use App\Models\Document\DocumentReportDefinition;
use App\Services\DocumentEngine\DataProviderRegistry;
use App\Services\DocumentEngine\FieldDictionary;
use App\Services\DocumentEngine\Providers\LeaveStatisticsDataProvider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ตั้งค่าเริ่มต้นของ Document Engine — Field Dictionary แหล่งข้อมูล และรายงานตัวอย่าง
 *
 * R02-8: สิทธิ์ `document.*` และเมนูศูนย์เอกสารย้ายไปประกาศใน manifest ของโมดูล (R-04/R-08)
 * ไม่สร้าง permission หรือเมนูจาก seeder อีกต่อไป (PROJECT_RULES ข้อ 5, 13)
 *
 * รันซ้ำได้ปลอดภัย (idempotent) ไม่ลบของเดิม
 */
class DocumentEngineSeeder extends Seeder
{
    public function run(): void
    {
        app(DataProviderRegistry::class)->sync();

        $this->syncFieldDictionary();
        $this->syncExampleReport();
    }

    /**
     * รายงานตัวอย่าง "สถิติการลาประจำเดือน" — จัดกลุ่มรายแผนก มียอดรวมรายกลุ่มและรวมทั้งสิ้น
     *
     * มีไว้ให้ผู้ดูแลเห็นโครงที่ใช้งานได้จริงทันที และคัดลอกไปสร้างรายงานของตนเองต่อได้
     */
    private function syncExampleReport(): void
    {
        DocumentReportDefinition::updateOrCreate(
            ['code' => 'LEAVE_MONTHLY_STATISTICS'],
            [
                'name_th' => 'รายงานสถิติการลาประจำเดือน',
                'module' => 'leave',
                'data_source_code' => LeaveStatisticsDataProvider::CODE,
                'filters' => ['DATE_RANGE', 'DEPARTMENT'],
                'columns' => [
                    ['key' => 'employee', 'label' => 'ชื่อ-นามสกุล'],
                    ['key' => 'department', 'label' => 'แผนก/งาน'],
                    ['key' => 'sick_days', 'label' => 'ลาป่วย'],
                    ['key' => 'personal_days', 'label' => 'ลากิจส่วนตัว'],
                    ['key' => 'vacation_days', 'label' => 'ลาพักผ่อน'],
                    ['key' => 'total_days', 'label' => 'รวม'],
                ],
                'grouping' => ['column' => 'department'],
                'aggregates' => [
                    ['column' => 'sick_days', 'function' => 'SUM'],
                    ['column' => 'personal_days', 'function' => 'SUM'],
                    ['column' => 'vacation_days', 'function' => 'SUM'],
                    ['column' => 'total_days', 'function' => 'SUM'],
                ],
                'layout' => ['title' => 'รายงานสถิติการลาประจำเดือน', 'signature' => []],
                'orientation' => 'portrait',
                'paper_size' => 'A4',
                'is_active' => true,
            ],
        );
    }

    /** Field Dictionary มาจาก catalog ของ provider โดยตรง — ที่เดียวที่นิยามว่ามีฟิลด์อะไรบ้าง */
    private function syncFieldDictionary(): void
    {
        app(FieldDictionary::class)->sync();

        $this->syncLegacyBindings();
    }

    /** ประเภทการลาที่ผูกแบบฟอร์มไว้แล้วต้องมีกติกา binding เสมอ (กรณีติดตั้งใหม่) */
    private function syncLegacyBindings(): void
    {
        // ประเภทการลาที่ผูกแบบฟอร์มไว้แล้วต้องมีกติกา binding เสมอ (กรณีติดตั้งใหม่)
        if (! DB::table('document_binding_rules')->exists()) {
            $templates = DB::table('document_templates')->pluck('id', 'code');

            foreach (DB::table('leave_types')->select('id', 'document_template_code')->get() as $type) {
                $templateId = $templates[$type->document_template_code] ?? null;

                if ($templateId !== null) {
                    DB::table('document_binding_rules')->insert([
                        'module' => 'LEAVE', 'entity_type' => 'LEAVE_TYPE', 'entity_id' => $type->id,
                        'document_template_id' => $templateId, 'priority' => 500, 'is_active' => true,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
