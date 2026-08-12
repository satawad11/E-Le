<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\DocumentIncoming;
use Illuminate\Http\Request;

class DocumentIncomingController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'document_id' => 'required|exists:saraban_documents,id',
            'receive_date' => 'required|date',
            'from_agency' => 'required|string|max:255',
        ]);

        $data['receiver_id'] = auth()->id();
        $incoming = DocumentIncoming::create($data);

        return response()->json($incoming, 201);
    }
}
