<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * RBAC-CLEANUP-01 — ล้างสิทธิ์ที่ไม่ได้ใช้ และปิด alias ที่ให้สิทธิ์เกินตั้งใจ
 *
 * ตรวจ 2026-10-17 (docs/ACCESS-GOVERNANCE-2569.md หัวข้อ 11) พบ:
 *
 * 1. alias ที่ให้สิทธิ์เกิน (ช่องโหว่):
 *    - attendance.ot_summary → overtime.management.review : ผู้ที่ควร "ดูสรุป OT" (HR Head, HR Officer, Auditor)
 *      อนุญาต/รับรองเวลา OT ได้ — HR Head ผู้ออกคำสั่ง OT จึงอนุมัติคำสั่งของตนเองได้
 *    - export_reports → leave.report.view : เจ้าหน้าที่สารบรรณเห็นรายงานการลาทั้งวิทยาลัย
 *      (export_reports ไม่ถูกตรวจที่ใดในโค้ดเลย มีผลผ่าน alias นี้เท่านั้น)
 *
 * 2. ชื่อเดิมที่มีชื่อใหม่แทนแล้ว — ย้ายการมอบ (บทบาท/รายบุคคล/เมนู) ไปชื่อใหม่ก่อนลบ ไม่มีใครเสียสิทธิ์:
 *      approve_leave → leave.approval.dept · view_all_attendances → attendance.view_all
 *      attendance.ot → overtime.operation.execute
 *    export_reports มอบ leave.report.view แทนเฉพาะผู้บริหารและงานบุคลากร (Director, Deputy Director, HR Head)
 *    ที่ใช้รายงานการลาจริง — Document Officer ไม่ได้รับ
 *
 * 3. สิทธิ์ที่ไม่มีโค้ดตรวจเลย (ติ๊กในหน้าบทบาทแล้วไม่มีผล) — ลบทิ้ง
 *
 * ไม่ลบตาราง ไม่แตะบทบาท · down() สร้างสิทธิ์/alias กลับ แต่ไม่คืนการมอบที่ลบไป
 */
return new class extends Migration
{
    /** ชื่อเดิม => ชื่อใหม่ ที่ย้ายการมอบทั้งหมดแล้วลบชื่อเดิม */
    private const MERGE = [
        'approve_leave' => 'leave.approval.dept',
        'approve_leaves' => 'leave.approval.dept',
        'view_all_attendances' => 'attendance.view_all',
        'attendance.ot' => 'overtime.operation.execute',
    ];

    /** บทบาทที่ได้ leave.report.view แทน export_reports */
    private const REPORT_ROLES = ['Director', 'Deputy Director', 'HR Head'];

    /** alias ที่ต้องเลิก (ชื่อเดิม) */
    private const DROP_ALIASES = [
        'attendance.ot_summary', 'export_reports',
        'approve_leave', 'view_all_attendances', 'attendance.ot',
    ];

    /** สิทธิ์ที่ไม่มีโค้ดตรวจ */
    private const UNUSED = [
        'export_reports', 'delete_users',
        'document.audit.view', 'document.final.view', 'document.generate',
        'leave.audit.view', 'leave.evidence.verify',
        'leave.view', 'leave.create', 'leave.cancel', 'leave.approve', 'leave.hr_verify', 'leave.export',
        'attendance.export',
        'saraban.view', 'saraban.config.manage', 'saraban.document.archive', 'saraban.document.dispatch',
    ];

    public function up(): void
    {
        $guard = 'web';
        $now = now();
        $id = fn (string $name) => DB::table('permissions')->where('name', $name)->where('guard_name', $guard)->value('id');
        $ensure = fn (string $name) => $id($name) ?? DB::table('permissions')->insertGetId([
            'name' => $name, 'guard_name' => $guard, 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (self::MERGE as $legacy => $canonical) {
            $from = $id($legacy);
            if ($from === null) {
                continue;
            }
            $to = $ensure($canonical);
            foreach (DB::table('role_has_permissions')->where('permission_id', $from)->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $to, 'role_id' => $roleId]);
            }
            foreach (DB::table('model_has_permissions')->where('permission_id', $from)->get() as $grant) {
                DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $to, 'model_type' => $grant->model_type, 'model_id' => $grant->model_id]);
            }
            if (DB::getSchemaBuilder()->hasTable('menu_permissions')) {
                foreach (DB::table('menu_permissions')->where('permission_id', $from)->pluck('menu_id') as $menuId) {
                    DB::table('menu_permissions')->insertOrIgnore(['permission_id' => $to, 'menu_id' => $menuId]);
                }
            }
            $this->renameScopes($legacy, $canonical);
        }

        $report = $ensure('leave.report.view');
        foreach (DB::table('roles')->whereIn('name', self::REPORT_ROLES)->where('guard_name', $guard)->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $report, 'role_id' => $roleId]);
        }

        if (DB::getSchemaBuilder()->hasTable('permission_aliases')) {
            DB::table('permission_aliases')->whereIn('legacy_name', self::DROP_ALIASES)->delete();
        }

        $remove = array_values(array_unique(array_merge(array_keys(self::MERGE), self::UNUSED)));
        $ids = DB::table('permissions')->whereIn('name', $remove)->where('guard_name', $guard)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        if (DB::getSchemaBuilder()->hasTable('menu_permissions')) {
            DB::table('menu_permissions')->whereIn('permission_id', $ids)->delete();
        }
        foreach (['permission_scopes', 'permission_denies'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->whereIn('permission_name', $remove)->delete();
            }
        }
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $now = now();
        foreach (array_unique(array_merge(array_keys(self::MERGE), self::UNUSED)) as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }
        if (DB::getSchemaBuilder()->hasTable('permission_aliases')) {
            foreach ([
                'attendance.ot_summary' => 'overtime.management.review', 'export_reports' => 'leave.report.view',
                'approve_leave' => 'leave.approval.dept', 'view_all_attendances' => 'attendance.view_all',
                'attendance.ot' => 'overtime.operation.execute',
            ] as $legacy => $canonical) {
                DB::table('permission_aliases')->updateOrInsert(['legacy_name' => $legacy], ['canonical_name' => $canonical, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** ขอบเขต/การปฏิเสธรายบุคคลที่ตั้งไว้กับชื่อเดิม ย้ายไปชื่อใหม่ (ไม่ทับแถวที่ชื่อใหม่มีอยู่แล้ว) */
    private function renameScopes(string $legacy, string $canonical): void
    {
        foreach (['permission_scopes', 'permission_denies'] as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->where('permission_name', $legacy)->get() as $row) {
                $exists = DB::table($table)->where('model_type', $row->model_type)->where('model_id', $row->model_id)
                    ->where('permission_name', $canonical)->exists();
                if (! $exists) {
                    DB::table($table)->where('id', $row->id)->update(['permission_name' => $canonical]);
                }
            }
        }
    }
};
