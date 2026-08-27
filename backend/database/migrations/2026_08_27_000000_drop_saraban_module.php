<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ถอนโมดูลสารบรรณอิเล็กทรอนิกส์ (e-Saraban) ออกจากระบบทั้งหมด
 *
 * ครอบคลุมทั้งตารางข้อมูลของโมดูล และ "ร่องรอย" ที่โมดูลฝากไว้ในตารางกลาง
 * (สิทธิ์, เมนู, ค่าตั้งค่าระบบ, แม่แบบเอกสาร, สายงานใน Workflow Engine,
 * ประเภทการแจ้งเตือน)
 *
 * ขอบเขตที่ **ไม่แตะ**: ระบบการลาออนไลน์ และระบบลงเวลาออนไลน์
 * ทั้งสองโมดูลไม่มี foreign key มายังตาราง `saraban_*` และไม่ใช้สิทธิ์
 * `saraban.*` จึงไม่ได้รับผลจาก migration นี้
 *
 * เป็น migration ทางเดียว (irreversible) — `down()` ไม่คืนข้อมูล
 * ต้องสำรองฐานข้อมูลก่อนรันบน production
 */
return new class extends Migration
{
    /**
     * เรียงจากตารางลูกไปตารางแม่ เพื่อไม่ให้ติด foreign key constraint
     */
    private const TABLES = [
        'saraban_document_circular_recipients',
        'saraban_document_circulars',
        'saraban_document_signatures',
        'saraban_document_workflow_steps',
        'saraban_document_workflow_templates',
        'saraban_document_user_states',
        'saraban_document_receivers',
        'saraban_document_attachments',
        'saraban_document_incoming',
        'saraban_document_outgoing',
        'saraban_audit_logs',
        'saraban_documents',
        'saraban_document_sequences',
        'saraban_document_types',
    ];

    private const SETTING_KEYS = [
        'enable_saraban_menu',
        'saraban_incoming_number_format',
        'saraban_outgoing_number_format',
        'saraban_garuda_logo',
        'require_pin_for_signature',
        'require_pin_for_circular_ack',
    ];

    public function up(): void
    {
        $this->purgeWorkflowDefinition();
        $this->purgeNotificationType();
        $this->purgeMenus();
        $this->purgePermissions();
        $this->purgeSettings();
        $this->purgeDocumentTemplates();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // ถอนโมดูลออกถาวร — ไม่มีการสร้างคืน ให้กู้จาก backup เท่านั้น
    }

    /**
     * สายงานอนุมัติของสารบรรณใน Workflow Engine
     *
     * ต้องลบ instance ก่อน version (FK เป็น restrictOnDelete) ส่วน task/action
     * ถูกลบตาม instance ด้วย cascade อยู่แล้ว
     */
    private function purgeWorkflowDefinition(): void
    {
        if (! Schema::hasTable('workflow_definitions')) {
            return;
        }

        $definitionIds = DB::table('workflow_definitions')
            ->where('code', 'saraban.document')
            ->orWhere('module', 'saraban')
            ->pluck('id');

        if ($definitionIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('workflow_versions')) {
            $versionIds = DB::table('workflow_versions')->whereIn('workflow_definition_id', $definitionIds)->pluck('id');

            if ($versionIds->isNotEmpty()) {
                if (Schema::hasTable('workflow_instances')) {
                    DB::table('workflow_instances')->whereIn('workflow_version_id', $versionIds)->delete();
                }

                DB::table('workflow_versions')->whereIn('id', $versionIds)->delete();
            }
        }

        DB::table('workflow_definitions')->whereIn('id', $definitionIds)->delete();
    }

    /**
     * ประเภทการแจ้งเตือน `saraban.task`
     *
     * ประวัติการแจ้งเตือนที่ส่งไปแล้วถือเป็น historical record (FK เป็น
     * restrictOnDelete) จึงลบทิ้งไม่ได้ ถ้ายังมีประวัติอ้างอยู่ให้ปิดการใช้งาน
     * ไว้แทน เพื่อไม่ให้มีการส่งแจ้งเตือนของโมดูลที่ถอดออกไปแล้วอีก
     */
    private function purgeNotificationType(): void
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

        // menu_permissions ผูกด้วย cascadeOnDelete จึงหายตามเมนูเอง
        DB::table('menus')->where('module', 'saraban')->orWhere('code', 'like', 'saraban%')->delete();
    }

    /**
     * สิทธิ์ `saraban.*` ทั้งชุด
     *
     * ตารางเชื่อมของ spatie (`role_has_permissions`, `model_has_permissions`)
     * ผูกแบบ cascade จึงถูกถอดออกจากทุกบทบาทและทุกผู้ใช้โดยอัตโนมัติ
     * ตัวบทบาท (เช่น "เจ้าหน้าที่สารบรรณกลาง") ยังอยู่ครบ ไม่มีผู้ใช้คนใดหลุด role
     */
    private function purgePermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->where('name', 'like', 'saraban.%')->delete();
    }

    private function purgeSettings(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->whereIn('key', self::SETTING_KEYS)->delete();
    }

    private function purgeDocumentTemplates(): void
    {
        if (! Schema::hasTable('document_templates')) {
            return;
        }

        DB::table('document_templates')->where('module', 'saraban')->delete();
    }
};
