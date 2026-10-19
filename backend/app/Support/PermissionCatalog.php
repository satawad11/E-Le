<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

/**
 * รายการสิทธิ์สำหรับหน้าจัดการบทบาท จัดกลุ่มตามโมดูลพร้อมคำอธิบายภาษาไทย (config/modules.php)
 *
 * หน้าจอไม่แสดงชื่อสิทธิ์ภายใน (เช่น leave.management.manage) — แสดงคำอธิบายแทน
 * สิทธิ์ที่มีในฐานข้อมูลแต่ยังไม่มีคำอธิบายจะอยู่กลุ่ม "อื่น ๆ" (มี test กันไม่ให้เกิด)
 */
class PermissionCatalog
{
    public const OTHER_GROUP = 'อื่น ๆ';

    /** @return array<string, array{module: string, label: string}> ชื่อสิทธิ์ => โมดูลและคำอธิบาย */
    public static function labels(): array
    {
        $labels = [];
        foreach (config('modules') as $key => $module) {
            $moduleName = $module['name_th'] ?? $module['name'] ?? $key;
            foreach ($module['permissions'] ?? [] as $name => $label) {
                if (is_string($name) && is_string($label)) {
                    $labels[$name] = ['module' => $moduleName, 'label' => $label];
                }
            }
        }

        return $labels;
    }

    public static function label(string $permission): string
    {
        return self::labels()[$permission]['label'] ?? $permission;
    }

    /**
     * @return list<array{name: string, permissions: list<array{id: int, name: string, label: string}>}>
     */
    public static function groups(?Collection $permissions = null): array
    {
        $labels = self::labels();
        $permissions ??= Permission::orderBy('name')->get();
        $order = array_values(array_unique(array_column($labels, 'module')));
        $groups = [];

        foreach ($permissions as $permission) {
            $meta = $labels[$permission->name] ?? ['module' => self::OTHER_GROUP, 'label' => $permission->name];
            $groups[$meta['module']][] = ['id' => $permission->id, 'name' => $permission->name, 'label' => $meta['label']];
        }

        $sorted = [];
        foreach ([...$order, self::OTHER_GROUP] as $module) {
            if (! empty($groups[$module])) {
                // เรียงตามลำดับที่ประกาศในโมดูล ไม่ใช่ตามตัวอักษรของชื่อภายใน
                $position = array_flip(array_keys(array_filter($labels, fn ($m) => $m['module'] === $module)));
                usort($groups[$module], fn ($a, $b) => ($position[$a['name']] ?? PHP_INT_MAX) <=> ($position[$b['name']] ?? PHP_INT_MAX));
                $sorted[] = ['name' => $module, 'permissions' => $groups[$module]];
            }
        }

        return $sorted;
    }
}
