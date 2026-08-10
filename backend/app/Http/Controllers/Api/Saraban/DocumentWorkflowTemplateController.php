<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\DocumentWorkflowTemplate;
use App\Services\Saraban\AuditLogService;
use Illuminate\Http\Request;

class DocumentWorkflowTemplateController extends Controller
{
    protected $auditLog;

    public function __construct(AuditLogService $auditLog)
    {
        $this->auditLog = $auditLog;
    }

    public function index()
    {
        $templates = DocumentWorkflowTemplate::orderBy('id', 'desc')->get();
        return response()->json($templates);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'steps_config' => 'required|array',
            'is_active' => 'boolean'
        ]);

        $template = DocumentWorkflowTemplate::create([
            'name' => $request->name,
            'description' => $request->description,
            'steps_config' => $request->steps_config,
            'is_active' => $request->is_active ?? true,
        ]);

        $this->auditLog->log('workflow_template.created', null, ['template_id' => $template->id, 'name' => $template->name]);

        return response()->json($template, 201);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'steps_config' => 'required|array',
            'is_active' => 'boolean'
        ]);

        $template = DocumentWorkflowTemplate::findOrFail($id);
        $template->update([
            'name' => $request->name,
            'description' => $request->description,
            'steps_config' => $request->steps_config,
            'is_active' => $request->is_active ?? $template->is_active,
        ]);

        $this->auditLog->log('workflow_template.updated', null, ['template_id' => $template->id]);

        return response()->json($template);
    }

    public function destroy($id)
    {
        $template = DocumentWorkflowTemplate::findOrFail($id);
        $this->auditLog->log('workflow_template.deleted', null, ['template_id' => $template->id, 'name' => $template->name]);
        $template->delete();

        return response()->json(null, 204);
    }
}
