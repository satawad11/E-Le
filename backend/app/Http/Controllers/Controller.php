<?php

namespace App\Http\Controllers;

use App\Authz\Contracts\AuthzResource;
use App\Authz\Decision;
use App\Authz\PermissionEngine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * ตรวจสิทธิ์ระดับรายการด้วย PermissionEngine (R04-5)
     *
     * รายการนอก scope ตอบ 404 เพื่อไม่บอกว่ามีอยู่ (IDOR) · ไม่มีสิทธิ์/ผิดกฎกลาง ตอบ 403 พร้อมเหตุผล
     *
     * @param  array{fields?: array<int, string>, entity?: string}  $context
     */
    protected function authorizeResource(string $permission, AuthzResource $resource, array $context = []): Decision
    {
        $decision = app(PermissionEngine::class)->can(request()->user(), $permission, $resource, $context);

        if ($decision->hidesExistence()) {
            abort(404);
        }
        abort_if($decision->denied(), 403, $decision->message);

        return $decision;
    }

    /**
     * ผ่านเมื่อ permission ใดก็ได้อนุญาต (R-06)
     * - มีตัวที่ครอบคลุมรายการแต่ผิดกฎกลาง → 403 พร้อมเหตุผล
     * - ไม่เช่นนั้น ถ้ามีตัวที่บอกว่านอก scope → 404 (ไม่บอกว่ามีอยู่) · ไม่มีสิทธิ์เลย → 403
     *
     * @param  array<int, string>  $permissions
     * @param  array{fields?: array<int, string>, entity?: string}  $context
     */
    protected function authorizeAnyResource(array $permissions, AuthzResource $resource, array $context = []): Decision
    {
        $engine = app(PermissionEngine::class);
        $denied = [];
        foreach ($permissions as $permission) {
            $decision = $engine->can(request()->user(), $permission, $resource, $context);
            if ($decision->allowed) {
                return $decision;
            }
            $denied[] = $decision;
        }

        $ruleDenied = array_values(array_filter($denied, fn (Decision $d) => $d->reason === Decision::RULE_DENIED));
        abort_if($ruleDenied !== [], 403, $ruleDenied[0]->message ?? '');
        abort_if(collect($denied)->contains(fn (Decision $d) => $d->hidesExistence()), 404);
        abort(403, $denied[0]->message ?? 'ไม่มีสิทธิ์ใช้งานฟังก์ชันนี้');
    }
}
