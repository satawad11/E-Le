<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\DocumentWorkflowTemplate;
use Illuminate\Http\Request;

class DocumentWorkflowTemplateController extends Controller
{
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

        return response()->json($template);
    }

    public function destroy($id)
    {
        $template = DocumentWorkflowTemplate::findOrFail($id);
        $template->delete();

        return response()->json(null, 204);
    }
}
