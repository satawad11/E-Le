<?php

namespace App\Services;

use App\Models\Menu;
use App\Models\User;
use App\Services\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Cache;

class MenuService
{
    public function __construct(private ModuleRegistry $modules) {}

    /**
     * Get accessible menu tree for the given user.
     */
    public function getUserMenuTree(User $user): array
    {
        $cacheKey = "user_menu_tree_{$user->id}_{$this->modules->stateFingerprint()}";

        return Cache::remember($cacheKey, 3600, function () use ($user) {
            // CLEANUP-02 (D5) — ตัดสินด้วย Gate เพื่อให้รู้จักชื่อที่เทียบเท่าและ explicit deny

            /*
             * LEGACY-04 (ปิดงาน) — เดิมบรรทัดนี้เช็ค role ชื่อ `admin`, `super_admin`,
             * `admin_saraban` ซึ่ง **ไม่มีอยู่จริงสักชื่อเดียว** `$isAdmin` จึงเป็น false
             * เสมอ และตรรกะ "ผู้ดูแลเห็นทุกเมนู" ไม่เคยทำงานเลย
             *
             * ตัดสินจากสิทธิ์จัดการเมนูแทน — `can()` ผ่าน Gate::before จึงครอบคลุม
             * Super Admin ให้อัตโนมัติโดยไม่ต้องอ้างชื่อ role ที่นี่
             */
            $isAdmin = $user->can('system.menu.manage');

            $rootMenus = Menu::with(['children.permissions', 'permissions'])
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->orderBy('sort_order', 'asc')
                ->get();

            $result = [];
            foreach ($rootMenus as $menu) {
                $formatted = $this->formatMenuItem($menu, $user, $isAdmin);
                if ($formatted) {
                    $result[] = $formatted;
                }
            }

            return $result;
        });
    }

    /**
     * Format menu item and filter authorized children.
     */
    protected function formatMenuItem(Menu $menu, User $user, bool $isAdmin): ?array
    {
        if (! $menu->is_active || ! $this->modules->has($menu->module) || ! $this->modules->isEnabled($menu->module)) {
            return null;
        }

        // Check if user is authorized for this menu item
        if (! $this->isAuthorized($menu, $user, $isAdmin)) {
            return null;
        }

        $children = [];
        if ($menu->children && $menu->children->count() > 0) {
            foreach ($menu->children as $child) {
                $formattedChild = $this->formatMenuItem($child, $user, $isAdmin);
                if ($formattedChild) {
                    $children[] = $formattedChild;
                }
            }
        }

        // If it's a parent menu without route_path and all children are unauthorized, omit parent
        if (empty($menu->route_path) && empty($menu->route_name) && empty($children) && $menu->children->count() > 0) {
            return null;
        }

        return [
            'id' => $menu->id,
            'code' => $menu->code,
            'title_th' => $menu->title_th,
            'title_en' => $menu->title_en,
            'module' => $menu->module,
            'icon' => $menu->icon,
            'route_name' => $menu->route_name,
            'route_path' => $menu->route_path,
            'component_path' => $menu->component_path,
            'is_external' => $menu->is_external,
            'sort_order' => $menu->sort_order,
            'children' => $children,
        ];
    }

    /**
     * Determine if user has permission for a menu item.
     */
    protected function isAuthorized(Menu $menu, User $user, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }

        $requiredPermissions = $menu->permissions->pluck('name')->toArray();

        // If no permissions attached, accessible to all logged in users
        if (empty($requiredPermissions)) {
            return true;
        }

        // User must have at least one of the required permissions
        return collect($requiredPermissions)->contains(fn (string $name) => $user->can($name));
    }

    /**
     * Clear menu cache for a specific user.
     */
    public function clearUserMenuCache(User $user): void
    {
        Cache::forget("user_menu_tree_{$user->id}_{$this->modules->stateFingerprint()}");
    }
}
