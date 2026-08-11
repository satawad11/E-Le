<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Services\Saraban\DocumentWorkflowService;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    protected $workflowService;

    public function __construct(DocumentWorkflowService $workflowService)
    {
        $this->workflowService = $workflowService;
    }

    public function submit(Request $request, $id)
    {
        $document = Document::findOrFail($id);
        $this->authorize('submit', $document);

        $comment = $request->input('comment');
        $this->workflowService->submitDocument($document, $request->user()->id, $comment, $request->ip());

        return response()->json(['message' => 'Document submitted for review successfully.']);
    }

    public function approve(Request $request, $id)
    {
        $document = Document::findOrFail($id);
        $this->authorize('approve', $document);
        
        $comment = $request->input('comment');
        $this->workflowService->approveDocument($document, $request->user()->id, $comment, $request->ip());

        return response()->json(['message' => 'Document approved successfully.']);
    }

    public function reject(Request $request, $id)
    {
        $request->validate([
            'comment' => 'required|string',
        ]);

        $document = Document::findOrFail($id);
        $this->authorize('approve', $document);
        
        $this->workflowService->rejectDocument($document, $request->user()->id, $request->input('comment'), $request->ip());

        return response()->json(['message' => 'Document rejected.']);
    }
}
