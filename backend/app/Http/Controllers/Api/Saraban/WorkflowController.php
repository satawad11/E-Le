<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Services\Saraban\AuditLogService;
use App\Services\Saraban\DocumentWorkflowService;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    protected $workflowService;

    protected $auditLog;

    public function __construct(DocumentWorkflowService $workflowService, AuditLogService $auditLog)
    {
        $this->workflowService = $workflowService;
        $this->auditLog = $auditLog;
    }

    public function submit(Request $request, $id)
    {
        $document = Document::findOrFail($id);
        $this->authorize('submit', $document);

        $comment = $request->input('comment');
        $this->workflowService->submitDocument($document, $request->user()->id, $comment, $request->ip());
        $this->auditLog->log('document.submitted', $document, ['comment' => $comment]);

        return response()->json(['message' => 'ส่งหนังสือเข้าสู่การกลั่นกรองเรียบร้อยแล้ว']);
    }

    public function approve(Request $request, $id)
    {
        $document = Document::findOrFail($id);
        $this->authorize('approve', $document);

        $comment = $request->input('comment');
        $this->workflowService->approveDocument($document, $request->user()->id, $comment, $request->ip());
        $this->auditLog->log('document.approved', $document, ['comment' => $comment]);

        return response()->json(['message' => 'อนุมัติหนังสือเรียบร้อยแล้ว']);
    }

    public function reject(Request $request, $id)
    {
        $request->validate([
            'comment' => 'required|string',
        ]);

        $document = Document::findOrFail($id);
        $this->authorize('approve', $document);

        $this->workflowService->rejectDocument($document, $request->user()->id, $request->input('comment'), $request->ip());
        $this->auditLog->log('document.rejected', $document, ['comment' => $request->input('comment')]);

        return response()->json(['message' => 'ไม่อนุมัติหนังสือเรียบร้อยแล้ว']);
    }
}
