<?php

use App\Http\Middleware\AttachRequestContext;
use App\Http\Middleware\EnsureAnyAbility;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

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
        $middleware->api(append: [
            EnsureModuleEnabled::class,
            AttachRequestContext::class,
            SecurityHeaders::class,
        ]);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            /*
             * CLEANUP-02 (D5) — ด่านสิทธิ์แบบ "อย่างน้อยหนึ่งข้อ" ที่เดินผ่าน Gate
             * จึงรู้จักทั้งชื่อที่เทียบเท่ากันและ explicit deny ต่างจาก `permission:`
             */
            'ability' => EnsureAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*'));
    })->create();
