<?php

namespace App\Http\Middleware;

use App\Models\Saraban\Document;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanViewClassifiedDocument
{
    public function handle(Request $request, Closure $next): Response
    {
        $document = $request->route('document');

        // Some routes bind the raw id instead of the model.
        if (! $document instanceof Document) {
            $id = $request->route('id');
            $document = $id ? Document::find($id) : null;
        }

        if ($document instanceof Document && $document->secrecy !== 'normal') {
            $user = $request->user();

            if (! $user || ! $user->can('saraban.document.view_classified')) {
                abort(403, 'คุณไม่มีสิทธิ์เข้าถึงหนังสือชั้นความลับ');
            }
        }

        return $next($request);
    }
}
