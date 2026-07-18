<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Saraban\Document;

class CanViewClassifiedDocument
{
    public function handle(Request $request, Closure $next): Response
    {
        $document = $request->route('document');

        if ($document instanceof Document) {
            if ($document->secrecy !== 'normal') {
                if (!auth()->user()->hasRole('admin_saraban')) {
                    abort(403, 'Unauthorized to view classified documents.');
                }
            }
        }

        return $next($request);
    }
}
