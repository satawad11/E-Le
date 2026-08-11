<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeInput
{
    /**
     * Handle an incoming request and sanitize inputs against XSS attacks.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $input = $request->all();

        array_walk_recursive($input, function (&$val) {
            if (is_string($val)) {
                // Strip dangerous script tags while keeping standard safe text
                $val = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $val);
                $val = preg_replace('/javascript:/i', '', $val);
            }
        });

        $request->merge($input);

        return $next($request);
    }
}
