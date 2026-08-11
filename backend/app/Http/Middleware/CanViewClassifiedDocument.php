<?php

namespace App\Http\Middleware;

use App\Models\Saraban\Document;
use App\Policies\Saraban\DocumentPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * กันหนังสือชั้นความลับที่ผูกกับ route binding {document} / {id}
 * (ชั้นตรวจซ้ำนอกเหนือจาก DocumentPolicy ที่ควบคุมใน controller)
 */
class CanViewClassifiedDocument
{
    public function handle(Request $request, Closure $next): Response
    {
        $document = $request->route('document');

        if (!$document instanceof Document) {
            $id = $request->route('id');
            $document = $id ? Document::find($id) : null;
        }

        if ($document instanceof Document && $document->secrecy !== 'normal') {
            $user = $request->user();

            if (!$user || !app(DocumentPolicy::class)->viewClassified($user, $document)) {
                abort(403, 'ไม่มีสิทธิ์เข้าถึงหนังสือชั้นความลับนี้');
            }
        }

        return $next($request);
    }
}
