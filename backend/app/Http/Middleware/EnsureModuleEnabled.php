<?php

namespace App\Http\Middleware;

use App\Services\Modules\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleEnabled
{
    public function __construct(private ModuleRegistry $modules) {}

    public function handle(Request $request, Closure $next): Response
    {
        $module = $this->modules->moduleForPath($request->path());
        if ($module !== null && ! $this->modules->isEnabled($module->code())) {
            abort(404);
        }

        return $next($request);
    }
}
