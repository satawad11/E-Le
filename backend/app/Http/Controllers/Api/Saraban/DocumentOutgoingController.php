<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\DocumentOutgoing;
use Illuminate\Http\Request;

class DocumentOutgoingController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'document_id' => 'required|exists:saraban_documents,id',
            'dispatch_date' => 'required|date',
            'to_agency' => 'required|string|max:255',
            'dispatch_method' => 'nullable|string|max:255',
        ]);
        
        $outgoing = DocumentOutgoing::create($data);

        return response()->json($outgoing, 201);
    }
}
