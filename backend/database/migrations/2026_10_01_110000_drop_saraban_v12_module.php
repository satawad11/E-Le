<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * ถอนโมดูลงานสารบรรณอิเล็กทรอนิกส์ V1.2 ออกจากระบบ (คำสั่งเจ้าของระบบ 2026-10-01)
 *
 * แนวเดียวกับ `2026_08_27_000000_drop_saraban_module` ซึ่งถอนรุ่นแรก — ลบตาราง `saraban_*`
 * ทั้ง 20 ตาราง และร่องรอยในตารางกลาง (สิทธิ์ เมนู สายงาน ประเภทแจ้งเตือน ธง แม่แบบ แหล่งข้อมูล)
 *
 * **ด่านกันข้อมูลหาย** — ถ้าตารางที่เก็บงานจริง (หนังสือ เลขหนังสือ ทะเบียนรับ การเวียน ลายเซ็น
 * แฟ้มเรื่อง การอายัด) มีข้อมูลอยู่ migration จะหยุดและไม่แตะอะไรเลย จนกว่าผู้ดูแลจะ
 * สำรองฐานข้อมูลแล้วตั้ง `SARABAN_DROP_CONFIRMED=true` · ตารางตั้งต้นที่ระบบ seed ให้เอง
 * (ประเภทหนังสือ ชุดเลข นโยบาย) ไม่นับเป็นข้อมูลของผู้ใช้
 *
 * ไม่แตะ: ระบบลา ลงเวลา งานล่วงเวลา ศูนย์ปฏิบัติการ (ย้ายเป็น `system.*` แล้วใน migration ก่อนหน้า)
 * ไฟล์ PDF ของหนังสือบนดิสก์ไม่ถูกลบ — เก็บไว้ให้ผู้ดูแลตัดสินเองหลังตรวจ backup
 *
 * เป็น migration ทางเดียว (irreversible) — `down()` ไม่คืนข้อมูล ให้กู้จาก backup เท่านั้น
 */
return new class extends Migration
{
    /** เรียงจากตารางลูกไปตารางแม่ */
    private const TABLES = [
        'saraban_signature_revocations',
        'saraban_case_documents',
        'saraban_redactions',
        'saraban_saved_views',
        'saraban_legal_holds',
        'saraban_retention_schedules',
        'saraban_distribution_recipients',
        'saraban_distributions',
        'saraban_document_annotations',
        'saraban_signature_evidences',
        'saraban_incoming_registrations',
        'saraban_document_numbers',
        'saraban_number_sequences',
        'saraban_number_series',
        'saraban_cases',
        'saraban_policy_versions',
        'saraban_policies',
        'saraban_document_versions',
        'saraban_documents',
        'saraban_document_types',
    ];

    /** ตารางที่มีแถวได้เฉพาะจากการใช้งานจริง — มีแถว = มีข้อมูลของผู้ใช้ */
    private const USER_DATA_TABLES = [
        'saraban_documents',
        'saraban_document_numbers',
        'saraban_incoming_registrations',
        'saraban_distributions',
        'saraban_signature_evidences',
        'saraban_document_annotations',
        'saraban_cases',
        'saraban_legal_holds',
        'saraban_retention_schedules',
        'saraban_saved_views',
    ];

    public function up(): void
    {
        $this->refuseToDestroyUnbackedData();

        DB::transaction(function () {
            $this->purgeWorkflowDefinitions();
            $this->purgeNotificationTypes();
            $this->purgeMenus();
            $this->purgePermissions();
            $this->purgeFeatureFlags();
            $this->purgeDocumentEngineTraces();
        });

        Schema::disableForeignKeyConstraints();

        try {
            foreach (self::TABLES as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (config('cache.default') === 'database' && Schema::hasTable('cache')) {
            DB::table('cache')->where('key', 'like', '%user_menu_tree_%')->delete();
        }
    }

    public function down(): void
    {
        // ถอนโมดูลออกถาวร — ไม่มีการสร้างคืน ให้กู้จาก backup เท่านั้น
    }

    private function refuseToDestroyUnbackedData(): void
    {
        $withData = collect(self::USER_DATA_TABLES)
            ->filter(fn (string $table) => Schema::hasTable($table) && DB::table($table)->exists())
            ->values()
            ->all();

        if ($withData === [] || filter_var(getenv('SARABAN_DROP_CONFIRMED') ?: env('SARABAN_DROP_CONFIRMED', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        throw new RuntimeException(
            'หยุดการถอนโมดูลสารบรรณ: ตารางต่อไปนี้มีข้อมูลการใช้งานจริง — '.implode(', ', $withData)
            .' · สำรองฐานข้อมูลก่อน (php artisan backup:run) แล้วรันใหม่ด้วย SARABAN_DROP_CONFIRMED=true'
            .' · ยังไม่มีการเปลี่ยนแปลงใดเกิดขึ้น'
        );
    }

    /**
     * สายงานของสารบรรณใน Workflow Engine — ลบ instance ก่อน version (FK restrictOnDelete)
     * task/action ถูกลบตาม instance ด้วย cascade
     */
    private function purgeWorkflowDefinitions(): void
    {
        if (! Schema::hasTable('workflow_definitions')) {
            return;
        }

        $definitionIds = DB::table('workflow_definitions')
            ->where('code', 'like', 'saraban.%')
            ->orWhere('module', 'saraban')
            ->pluck('id');

        if ($definitionIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('workflow_versions')) {
            $versionIds = DB::table('workflow_versions')->whereIn('workflow_definition_id', $definitionIds)->pluck('id');

            if ($versionIds->isNotEmpty() && Schema::hasTable('workflow_instances')) {
                DB::table('workflow_instances')->whereIn('workflow_version_id', $versionIds)->delete();
            }

            DB::table('workflow_versions')->whereIn('id', $versionIds)->delete();
        }

        DB::table('workflow_definitions')->whereIn('id', $definitionIds)->delete();
    }

    /**
     * ประเภทการแจ้งเตือนของสารบรรณ — มีประวัติส่งแล้ว (FK restrictOnDelete) ให้ปิดไว้แทนการลบ
     */
    private function purgeNotificationTypes(): void
    {
        if (! Schema::hasTable('notification_types')) {
            return;
        }

        $typeIds = DB::table('notification_types')
            ->where('module', 'saraban')
            ->orWhere('code', 'like', 'saraban.%')
            ->pluck('id');

        if ($typeIds->isEmpty()) {
            return;
        }

        $hasHistory = Schema::hasTable('notification_events')
            && DB::table('notification_events')->whereIn('notification_type_id', $typeIds)->exists();

        if ($hasHistory) {
            DB::table('notification_types')->whereIn('id', $typeIds)->update(['is_active' => false]);

            return;
        }

        foreach (['notification_preferences', 'notification_templates'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->whereIn('notification_type_id', $typeIds)->delete();
            }
        }

        DB::table('notification_types')->whereIn('id', $typeIds)->delete();
    }

    private function purgeMenus(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        // ลูกก่อนแม่ — menu_permissions ผูกแบบ cascade จึงหายตามเมนูเอง
        $ids = DB::table('menus')->where('module', 'saraban')->orWhere('code', 'like', 'saraban%')->pluck('id');
        DB::table('menus')->whereIn('parent_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();
    }

    /**
     * สิทธิ์ `saraban.*` ที่เหลือ (ชุดปฏิบัติการย้ายเป็น `system.*` ไปแล้ว)
     * ตัวบทบาทยังอยู่ครบ ไม่มีผู้ใช้คนใดหลุด role
     */
    private function purgePermissions(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('name', 'like', 'saraban.%')->delete();
        }
    }

    private function purgeFeatureFlags(): void
    {
        if (Schema::hasTable('feature_flags')) {
            DB::table('feature_flags')->where('key', 'like', 'saraban.%')->delete();
        }
    }

    /**
     * แม่แบบ/แหล่งข้อมูลของสารบรรณใน Document Engine
     *
     * แม่แบบที่มีเอกสารอ้างอยู่ห้ามลบ (version ผูกแบบ restrictOnDelete) — ปิดใช้งานไว้แทน
     */
    private function purgeDocumentEngineTraces(): void
    {
        if (Schema::hasTable('document_templates')) {
            foreach (DB::table('document_templates')->where('module', 'saraban')->pluck('id') as $templateId) {
                $versionIds = DB::table('document_template_versions')->where('document_template_id', $templateId)->pluck('id');
                $referenced = Schema::hasTable('document_generated_files')
                    && DB::table('document_generated_files')->whereIn('document_template_version_id', $versionIds)->exists();

                if ($referenced) {
                    DB::table('document_templates')->where('id', $templateId)->update(['is_active' => false]);

                    continue;
                }

                if (Schema::hasTable('document_field_mappings')) {
                    DB::table('document_field_mappings')->whereIn('document_template_version_id', $versionIds)->delete();
                }
                if (Schema::hasTable('document_binding_rules')) {
                    DB::table('document_binding_rules')->where('document_template_id', $templateId)->delete();
                }
                DB::table('document_template_versions')->whereIn('id', $versionIds)->delete();
                DB::table('document_templates')->where('id', $templateId)->delete();
            }
        }

        if (Schema::hasTable('document_data_sources')) {
            DB::table('document_data_sources')->where('code', 'SARABAN_DOCUMENT_DETAIL')->delete();
        }

        if (Schema::hasTable('document_field_definitions')) {
            DB::table('document_field_definitions')->where('code', 'like', 'saraban.%')->update(['is_active' => false]);
        }
    }
};
