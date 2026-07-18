<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\DocumentType;
use Illuminate\Http\Request;

class DocumentTypeController extends Controller
{
    public function index()
    {
        $types = DocumentType::orderBy('id', 'asc')->get();
        return response()->json($types);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
        ]);

        $type = DocumentType::create($data);
        return response()->json($type, 201);
    }

    public function update(Request $request, DocumentType $type)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
        ]);

        $type->update($data);
        return response()->json($type);
    }

    public function destroy(DocumentType $type)
    {
        $type->delete();
        return response()->json(null, 204);
    }
}
