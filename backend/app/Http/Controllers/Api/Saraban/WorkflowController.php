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

    private function checkPermission(Request $request, Document $document, string $action)
    {
        $user = $request->user();
        
        // Super Admin has all rights
        if ($user->hasRole(['Super Admin', 'SuperAdmin'])) {
            return true;
        }

        $isSarabanOfficer = $user->hasRole(['Saraban Officer', 'เจ้าหน้าที่สารบัญ', 'admin_saraban']);
        $isManager = $user->hasRole(['Director', 'Deputy Director', 'Deputy', 'HR Head', 'HRHead']);
        $isCreator = $document->created_by === $user->id;

        if ($action === 'submit' && ($isSarabanOfficer || $isCreator)) {
            return true;
        }

        if (in_array($action, ['approve', 'reject']) && ($isSarabanOfficer || $isManager)) {
            return true;
        }

        abort(403, 'คุณไม่มีสิทธิ์ดำเนินการนี้ (Unauthorized Action)');
    }

    public function submit(Request $request, $id)
    {
        $document = Document::findOrFail($id);
        $this->checkPermission($request, $document, 'submit');
        
        $comment = $request->input('comment');
        $this->workflowService->submitDocument($document, $request->user()->id, $comment, $request->ip());

        return response()->json(['message' => 'Document submitted for review successfully.']);
    }

    public function approve(Request $request, $id)
    {
        $document = Document::findOrFail($id);
        $this->checkPermission($request, $document, 'approve');
        
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
        $this->checkPermission($request, $document, 'reject');
        
        $this->workflowService->rejectDocument($document, $request->user()->id, $request->input('comment'), $request->ip());

        return response()->json(['message' => 'Document rejected.']);
    }
}
