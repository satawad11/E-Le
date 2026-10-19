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
}
