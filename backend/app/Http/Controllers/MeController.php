<?php

namespace App\Http\Controllers;

use App\Authz\Grant;
use App\Authz\Menu\MenuBuilder;
use App\Authz\PermissionEngine;
use App\Models\OrgUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * สิทธิ์และเมนูของผู้ใช้ปัจจุบัน (R04-6) — frontend ใช้ซ่อน/แสดงเท่านั้น การตรวจจริงอยู่ที่ server ทุก endpoint
 */
class MeController extends Controller
{
    public function permissions(Request $request, PermissionEngine $engine): JsonResponse
    {
        $grants = $engine->effective($request->user());
        $unitNames = OrgUnit::whereIn('id', $grants->flatMap(fn (Grant $g) => array_keys($g->anchors))->unique()->all())->pluck('name', 'id');

        return response()->json([
            'names' => $grants->pluck('permission')->unique()->sort()->values(),
            'grants' => $grants->map(fn (Grant $g) => [
                'permission' => $g->permission,
                'scope' => $g->scope,
                'source' => ['type' => $g->sourceType, 'label' => $g->label, 'template' => $g->template],
                'units' => in_array($g->scope, ['unit', 'subtree', 'assigned'], true)
                    ? collect(array_keys($g->anchors))->map(fn ($id) => $unitNames[$id] ?? null)->filter()->values()
                    : [],
            ])->values(),
        ]);
    }

    public function menu(Request $request, MenuBuilder $menus): JsonResponse
    {
        return response()->json(['groups' => $menus->forUser($request->user())]);
    }
}
