<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * รวมสิทธิ์ชื่อเดิมที่ซ้ำความหมายกับชื่อมาตรฐาน แล้วลบชื่อเดิมทิ้ง (เจ้าของระบบขอ 18/10/2569: "ตัวไหนไม่ใช้งาน ให้ลบทิ้ง")
 *
 *   edit_users       → system.user.manage
 *   view_users       → system.user.view
 *   manage_settings  → system.setting.manage
 *
 * ทั้งสามคู่ระบบถือว่าเป็นสิทธิ์เดียวกันอยู่แล้ว (permission_aliases) จึงแสดงซ้ำในหน้าจัดการบทบาท
 * RBAC-CLEANUP-01 คงชื่อเดิมไว้เพราะ route ยังตรวจด้วยชื่อเดิม — commit นี้ย้าย route/โค้ดไปใช้ชื่อมาตรฐานแล้ว
 *
 * ย้ายทุกที่ที่อ้างชื่อเดิม: สิทธิ์ของบทบาท, สิทธิ์รายบุคคล, สิทธิ์ของเมนู, ขอบเขตรายคน (permission_scopes)
 * และขั้นอนุมัติที่ระบุชื่อสิทธิ์ (approval_steps) — ผู้ที่เคยมีชื่อเดิมได้ชื่อมาตรฐานแทน สิทธิ์ใช้งานจริงไม่เปลี่ยน
 */
return new class extends Migration
{
    private const MAP = [
        'edit_users' => 'system.user.manage',
        'view_users' => 'system.user.view',
        'manage_settings' => 'system.setting.manage',
    ];

    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        foreach (self::MAP as $legacy => $canonical) {
            $legacyId = DB::table('permissions')->where('name', $legacy)->where('guard_name', $guard)->value('id');
            if (! $legacyId) {
                continue;
            }
            $canonicalId = $this->ensurePermission($canonical, $guard);

            $this->copyPivot('role_has_permissions', 'role_id', $legacyId, $canonicalId);
            $this->copyPivot('model_has_permissions', ['model_type', 'model_id'], $legacyId, $canonicalId);
            if (Schema::hasTable('menu_permissions')) {
                $this->copyPivot('menu_permissions', 'menu_id', $legacyId, $canonicalId);
            }
            $this->renameText('permission_scopes', 'permission_name', $legacy, $canonical, ['model_type', 'model_id']);
            $this->renameText('approval_steps', 'permission_name', $legacy, $canonical);

            DB::table('role_has_permissions')->where('permission_id', $legacyId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $legacyId)->delete();
            if (Schema::hasTable('menu_permissions')) {
                DB::table('menu_permissions')->where('permission_id', $legacyId)->delete();
            }
            DB::table('permissions')->where('id', $legacyId)->delete();
        }

        if (Schema::hasTable('permission_aliases')) {
            DB::table('permission_aliases')->whereIn('legacy_name', array_keys(self::MAP))->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** สร้างชื่อเดิมกลับและให้ผู้ที่ถือชื่อมาตรฐานได้ชื่อเดิมด้วย (ชื่อมาตรฐานคงไว้) */
    public function down(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        foreach (self::MAP as $legacy => $canonical) {
            $canonicalId = DB::table('permissions')->where('name', $canonical)->where('guard_name', $guard)->value('id');
            if (! $canonicalId) {
                continue;
            }
            $legacyId = $this->ensurePermission($legacy, $guard);
            $this->copyPivot('role_has_permissions', 'role_id', $canonicalId, $legacyId);
            $this->copyPivot('model_has_permissions', ['model_type', 'model_id'], $canonicalId, $legacyId);
            if (Schema::hasTable('menu_permissions')) {
                $this->copyPivot('menu_permissions', 'menu_id', $canonicalId, $legacyId);
            }
            if (Schema::hasTable('permission_aliases') && ! DB::table('permission_aliases')->where('legacy_name', $legacy)->exists()) {
                DB::table('permission_aliases')->insert(['legacy_name' => $legacy, 'canonical_name' => $canonical, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ensurePermission(string $name, string $guard): int
    {
        $id = DB::table('permissions')->where('name', $name)->where('guard_name', $guard)->value('id');

        return $id ?: DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => $guard, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** คัดลอกแถว pivot จากสิทธิ์หนึ่งไปอีกสิทธิ์ โดยไม่สร้างแถวซ้ำ */
    private function copyPivot(string $table, string|array $keys, int $fromId, int $toId): void
    {
        $keys = (array) $keys;
        foreach (DB::table($table)->where('permission_id', $fromId)->get($keys) as $row) {
            $match = collect($keys)->mapWithKeys(fn ($k) => [$k => $row->{$k}])->all();
            if (! DB::table($table)->where($match)->where('permission_id', $toId)->exists()) {
                DB::table($table)->insert($match + ['permission_id' => $toId]);
            }
        }
    }

    /** เปลี่ยนชื่อสิทธิ์ที่เก็บเป็นข้อความ — ถ้ามีแถวชื่อมาตรฐานของเจ้าของเดียวกันอยู่แล้ว ให้ลบแถวชื่อเดิมแทน */
    private function renameText(string $table, string $column, string $from, string $to, array $ownerKeys = []): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        foreach (DB::table($table)->where($column, $from)->get() as $row) {
            $owner = collect($ownerKeys)->mapWithKeys(fn ($k) => [$k => $row->{$k}])->all();
            $duplicate = $ownerKeys !== [] && DB::table($table)->where($owner)->where($column, $to)->exists();
            $duplicate
                ? DB::table($table)->where('id', $row->id)->delete()
                : DB::table($table)->where('id', $row->id)->update([$column => $to, 'updated_at' => now()]);
        }
    }
};
