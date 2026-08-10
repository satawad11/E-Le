<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Services\Saraban\AuditLogService;
use Illuminate\Http\Request;

class DocumentTypeController extends Controller
{
    protected $auditLog;

    public function __construct(AuditLogService $auditLog)
    {
        $this->auditLog = $auditLog;
    }

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
        $this->auditLog->log('document_type.created', null, ['type_id' => $type->id, 'name' => $type->name]);

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
        $this->auditLog->log('document_type.updated', null, ['type_id' => $type->id, 'changed' => array_keys($data)]);

        return response()->json($type);
    }

    public function destroy(DocumentType $type)
    {
        // Deleting a type that documents still reference would orphan their type_id.
        if (Document::where('type_id', $type->id)->exists()) {
            return response()->json([
                'message' => 'ไม่สามารถลบประเภทหนังสือนี้ได้ เนื่องจากมีเอกสารใช้งานอยู่',
            ], 422);
        }

        $this->auditLog->log('document_type.deleted', null, ['type_id' => $type->id, 'name' => $type->name]);
        $type->delete();

        return response()->json(null, 204);
    }
}
