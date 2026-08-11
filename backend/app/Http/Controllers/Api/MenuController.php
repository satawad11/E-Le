<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Services\MenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;

class MenuController extends Controller
{
    protected MenuService $menuService;

    public function __construct(MenuService $menuService)
    {
        $this->menuService = $menuService;
    }

    /**
     * Return user accessible menu tree.
     */
    public function index(Request $request): JsonResponse
    {
        $menus = $this->menuService->getUserMenuTree($request->user());

        return response()->json([
            'success' => true,
            'data' => $menus,
        ]);
    }

    /**
     * List all menus and available permissions for Admin management.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $menus = Menu::with(['permissions', 'children'])->orderBy('sort_order', 'asc')->get();
        $allPermissions = Permission::orderBy('name', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'menus' => $menus,
                'available_permissions' => $allPermissions,
            ],
        ]);
    }

    /**
     * Update menu permissions and status (Admin only).
     */
    public function updatePermissions(Request $request, Menu $menu): JsonResponse
    {
        $request->validate([
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'exists:permissions,id',
            'is_active' => 'nullable|boolean',
        ]);

        if ($request->has('permission_ids')) {
            $menu->permissions()->sync($request->input('permission_ids', []));
        }

        if ($request->has('is_active')) {
            $menu->is_active = $request->boolean('is_active');
            $menu->save();
        }

        // Flush menu cache so changes take effect immediately
        Cache::flush();

        return response()->json([
            'success' => true,
            'message' => 'บันทึกการแก้ไขสิทธิ์เมนูเรียบร้อยแล้ว',
            'data' => $menu->load('permissions'),
        ]);
    }
}
