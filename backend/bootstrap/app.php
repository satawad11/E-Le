<?php

use App\Http\Middleware\AttachRequestContext;
use App\Http\Middleware\CanViewClassifiedDocument;
use App\Http\Middleware\EnsureMfaSatisfied;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureRouteUsesPermissionEngine;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')->prefix('api')->group(base_path('routes/leave_government.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // deny by default: route ต้องประกาศ ->permission() ที่ต่อ PermissionEngine — ทำงานก่อน route model binding
        // R-05: ผู้ที่ต้องใช้ MFA ต้องถือ token ที่ผ่าน MFA แล้ว (ตรวจทุก request)
        $middleware->api(prepend: [
            EnsureRouteUsesPermissionEngine::class,
            EnsureMfaSatisfied::class,
        ]);
        $middleware->api(append: [
            EnsureModuleEnabled::class,
            AttachRequestContext::class,
            SecurityHeaders::class,
        ]);
        $middleware->alias([
            'permission' => RequirePermission::class,
            'saraban.classified' => CanViewClassifiedDocument::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*'));
    })->create();
