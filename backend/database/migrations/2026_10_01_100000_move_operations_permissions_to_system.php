<?php

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * ย้ายศูนย์ปฏิบัติการระบบออกจากโมดูลสารบรรณ (ก่อนถอดสารบรรณ)
 *
 * คิวข้อผิดพลาด ธงความสามารถ webhook และทะเบียนหลักฐาน เป็นของทั้งระบบ แต่สร้างมา
 * พร้อมสารบรรณจึงใช้สิทธิ์ `saraban.*` · การถอดสารบรรณจะลบสิทธิ์ `saraban.*` ทั้งหมด
 * ผู้ที่ดูแลระบบอยู่วันนี้จะเสียสิทธิ์ไปด้วย จึงต้องย้ายก่อน
 *
 * **เปลี่ยนชื่อแถวเดิม ไม่สร้างใหม่** — การผูกกับบทบาท/ผู้ใช้/เมนูตามมาเอง
 * ใครเคยมีสิทธิ์ใดก็ยังมีสิทธิ์เดียวกัน ไม่มีใครได้หรือเสียสิทธิ์จาก migration นี้
 */
return new class extends Migration
{
    private const RENAMES = [
        'saraban.operations.view' => 'system.operations.view',
        'saraban.operations.retry' => 'system.operations.retry',
        'saraban.exception.view' => 'system.exception.view',
        'saraban.exception.manage' => 'system.exception.manage',
        'saraban.feature_flags.manage' => 'system.feature_flags.manage',
    ];

    private const NEW_MENUS = ['settings.operations', 'settings.compliance'];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::RENAMES as $old => $new) {
                $this->renamePermission($old, $new);
            }

            // ค่าที่ผู้ดูแลตั้งไว้กับธง webhook ต้องตามมาด้วย ไม่งั้นธงกลับเป็นค่าเริ่มต้นเงียบ ๆ
            if (Schema::hasTable('feature_flags')
                && ! DB::table('feature_flags')->where('key', 'system.webhooks')->exists()) {
                DB::table('feature_flags')->where('key', 'saraban.webhooks')
                    ->update(['key' => 'system.webhooks', 'module' => 'system']);
            }

            $this->addMenus();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (config('cache.default') === 'database' && Schema::hasTable('cache')) {
            DB::table('cache')->where('key', 'like', '%user_menu_tree_%')->delete();
        }
    }

    private function renamePermission(string $old, string $new): void
    {
        $oldRow = DB::table('permissions')->where('name', $old)->where('guard_name', 'web')->first();

        if ($oldRow === null) {
            return;
        }

        $newRow = DB::table('permissions')->where('name', $new)->where('guard_name', 'web')->first();

        if ($newRow === null) {
            DB::table('permissions')->where('id', $oldRow->id)->update(['name' => $new, 'updated_at' => now()]);

            return;
        }

        // มีทั้งสองชื่อ (เช่นมีคนสร้างชื่อใหม่ไว้ก่อน) — ย้ายการผูกทั้งหมดไปที่ชื่อใหม่แล้วลบชื่อเดิม
        foreach (['role_has_permissions' => 'role_id', 'model_has_permissions' => 'model_id', 'menu_permissions' => 'menu_id'] as $table => $owner) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = $table === 'model_has_permissions' ? ['model_id', 'model_type'] : [$owner];

            DB::table($table)->where('permission_id', $oldRow->id)->get()->each(function ($row) use ($table, $columns, $newRow) {
                $match = ['permission_id' => $newRow->id];
                foreach ($columns as $column) {
                    $match[$column] = $row->{$column};
                }

                if (! DB::table($table)->where($match)->exists()) {
                    DB::table($table)->insert($match);
                }
            });

            DB::table($table)->where('permission_id', $oldRow->id)->delete();
        }

        DB::table('permissions')->where('id', $oldRow->id)->delete();
    }

    private function addMenus(): void
    {
        $branch = collect(MenuSeeder::TREE)->firstWhere('code', 'settings');
        $parent = Menu::query()->where('code', 'settings')->first();

        if ($branch === null || $parent === null) {
            return;
        }

        foreach ($branch['children'] as $order => $child) {
            if (! in_array($child['code'], self::NEW_MENUS, true)) {
                continue;
            }

            $menu = Menu::updateOrCreate(['code' => $child['code']], [
                'title_th' => $child['title_th'],
                'title_en' => $child['title_en'],
                'module' => $child['module'] ?? $branch['module'],
                'icon' => $child['icon'] ?? null,
                'route_name' => $child['route_name'],
                'route_path' => $child['route_path'],
                'component_path' => null,
                'parent_id' => $parent->id,
                'sort_order' => $order + 1,
                'is_external' => false,
            ]);

            $menu->permissions()->sync(collect($child['permissions'])
                ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->id)
                ->all());
        }
    }

    public function down(): void
    {
        // ไม่ย้อนกลับ — ชื่อ saraban.* จะถูกลบไปพร้อมโมดูลสารบรรณ การคืนชื่อเดิมจะทำให้ผู้ดูแลเสียสิทธิ์
    }
};
