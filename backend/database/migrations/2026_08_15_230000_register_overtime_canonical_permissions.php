<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MAP = [
        'attendance.ot' => 'overtime.operation.execute',
        'attendance.ot_summary' => 'overtime.management.review',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::MAP as $legacy => $canonical) {
            $canonicalId = DB::table('permissions')->where('name', $canonical)->where('guard_name', 'web')->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $canonical,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            DB::table('permission_aliases')->updateOrInsert(
                ['legacy_name' => $legacy],
                ['canonical_name' => $canonical, 'created_at' => $now, 'updated_at' => $now],
            );

            $legacyId = DB::table('permissions')->where('name', $legacy)->where('guard_name', 'web')->value('id');
            if ($legacyId === null) {
                continue;
            }

            foreach (DB::table('role_has_permissions')->where('permission_id', $legacyId)->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $canonicalId,
                    'role_id' => $roleId,
                ]);
            }

            foreach (DB::table('model_has_permissions')->where('permission_id', $legacyId)->get() as $grant) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $canonicalId,
                    'model_type' => $grant->model_type,
                    'model_id' => $grant->model_id,
                ]);
            }
        }

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        DB::table('permission_aliases')->whereIn('legacy_name', array_keys(self::MAP))->delete();

        $ids = DB::table('permissions')->whereIn('name', array_values(self::MAP))->where('guard_name', 'web')->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app()['cache']->forget('spatie.permission.cache');
    }
};
