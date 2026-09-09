<?php

namespace Database\Seeders;

use App\Models\Document\DocumentReportDefinition;
use App\Services\DocumentEngine\DataProviderRegistry;
use App\Services\DocumentEngine\FieldDictionarySynchronizer;
use App\Services\DocumentEngine\Providers\LeaveStatisticsDataProvider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ตั้งค่าเริ่มต้นของ Document Engine — สิทธิ์ Field Dictionary และแหล่งข้อมูล
 *
 * รันซ้ำได้ปลอดภัย (idempotent) ไม่ลบของเดิม และไม่แตะสิทธิ์อื่นในระบบ
 */
class DocumentEngineSeeder extends Seeder
{
    /** สิทธิ์เฉพาะของศูนย์จัดการเอกสาร (ข้อ 29 ของข้อกำหนด) */
    private const PERMISSIONS = [
        'document.template.view' => 'ดูแม่แบบเอกสารและรายงาน',
        'document.template.manage' => 'จัดการแม่แบบ เวอร์ชัน และตำแหน่งฟิลด์',
        'document.report.view' => 'ดูรายงานที่สร้างจากศูนย์เอกสาร',
        'document.report.manage' => 'จัดการนิยามรายงาน',
        'document.generate' => 'สั่งออกเอกสารจากระบบ',
        'document.final.view' => 'ดูเอกสารฉบับสมบูรณ์ที่ออกไปแล้ว',
        'document.final.download' => 'ดาวน์โหลดเอกสารฉบับสมบูรณ์',
        'document.audit.view' => 'ดูประวัติการใช้งานศูนย์เอกสาร',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name => $description) {
            Permission::findOrCreate($name, 'web');
        }

        // สิทธิ์ที่เพิ่งสร้างจะยังไม่อยู่ในแคชของ Spatie การมอบให้บทบาททันทีจึงต้องล้างแคชก่อน
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /*
         * ให้สิทธิ์ชุดใหม่กับบทบาทที่ดูแลระบบอยู่แล้วเท่านั้น
         * ไม่แตะบทบาทอื่น เพื่อไม่ให้ใครได้สิทธิ์เพิ่มโดยไม่ตั้งใจ
         */
        foreach (['Super Admin', 'ผู้ดูแลระบบสูงสุด'] as $roleName) {
            Role::where('name', $roleName)->first()?->givePermissionTo(array_keys(self::PERMISSIONS));
        }

        app(DataProviderRegistry::class)->sync();

        // พจนานุกรมฟิลด์มีแหล่งความจริงเดียวที่ `FieldDictionarySynchronizer` — เรียกซ้ำได้เสมอ
        app(FieldDictionarySynchronizer::class)->sync();
        $this->seedBindingRules();
        /*
         * CLEANUP-02 — เมนูมีแหล่งความจริงเดียวคือ `MenuSeeder::TREE`
         *
         * เดิม seeder นี้เพิ่มเมนู `admin.document_center` เองอีกรายการหนึ่ง ทั้งที่หน้าเดียวกัน
         * มีอยู่แล้วในโครงเมนูหลักในชื่อ `settings.document_center` และเนื่องจากกิ่งแม่ที่มันหา
         * (`code = 'admin'`) ไม่มีอยู่แล้ว เมนูซ้ำนี้จึงไปโผล่เป็น "กลุ่มเมนูระดับบนสุด"
         * บนระบบที่ติดตั้งใหม่ทุกครั้ง
         */
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

    /** ประเภทการลาที่ผูกแบบฟอร์มไว้แล้วต้องมีกติกา binding เสมอ (กรณีติดตั้งใหม่) */
    private function seedBindingRules(): void
    {
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
