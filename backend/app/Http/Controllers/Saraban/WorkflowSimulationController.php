<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Workflow\WorkflowDefinition;
use App\Services\Saraban\DocumentWorkflowService;
use App\Services\Saraban\WorkflowSimulationService;
use Illuminate\Http\Request;

/**
 * จำลองเส้นทางเดินหนังสือ (SoT §12 · INT-09)
 *
 * เป็น endpoint แบบ **อ่านอย่างเดียว** แม้จะใช้ POST (เพราะรับพารามิเตอร์เป็น body)
 * — ไม่สร้าง instance ไม่ออกเลข ไม่ส่งแจ้งเตือน และไม่แก้สถานะสิ่งใด
 *
 * ต้องมีสิทธิ์ `saraban.workflow.simulate` แยกจากสิทธิ์เดินเรื่องจริง เพราะการเห็น
 * ว่า "ใครมีอำนาจในขั้นใดบ้าง" เป็นข้อมูลเชิงโครงสร้างอำนาจขององค์กร
 */
class WorkflowSimulationController extends Controller
{
    public function __construct(private readonly WorkflowSimulationService $simulator) {}

    public function simulate(Request $request)
    {
        $data = $request->validate([
            'org_unit_id' => ['required', 'integer'],
            'document_type_id' => ['nullable', 'integer'],
            'workflow_version_id' => ['nullable', 'integer'],
            'as_of' => ['nullable', 'date'],
        ]);

        return response()->json($this->simulator->simulate($data));
    }

    /** เวอร์ชันของสายงานทั้งหมด เพื่อให้เลือกจำลองเวอร์ชันร่างได้ก่อนเผยแพร่ */
    public function versions()
    {
        $definition = WorkflowDefinition::query()
            ->where('code', DocumentWorkflowService::DEFINITION_CODE)
            ->first();

        return response()->json([
            'versions' => $definition === null ? [] : $definition->versions()
                ->orderByDesc('version')
                ->get(['id', 'version', 'status', 'effective_from', 'effective_to']),
        ]);
    }
}
