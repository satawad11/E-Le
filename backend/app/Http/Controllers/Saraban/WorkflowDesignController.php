<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Workflow\WorkflowVersion;
use App\Services\Modules\ModuleRegistry;
use App\Services\Saraban\WorkflowDesignException;
use App\Services\Saraban\WorkflowDesignService;
use Illuminate\Http\Request;

/**
 * จัดการสายการเดินหนังสือของงานสารบรรณ (RISK-26 · NAV §5.2)
 *
 * **แยกจากสายการอนุมัติใบลาโดยเจตนา** — หน้าจัดการของโมดูลการลา
 * (`/admin/workflow`) แก้ลำดับผู้อนุมัติใบลาซึ่งเป็นคนละเรื่องและคนละผู้ดูแล
 * ตัวนี้แตะได้เฉพาะ definition ของสารบรรณตัวเดียวเท่านั้น — ทุก endpoint ตรวจว่า
 * เวอร์ชันที่ส่งมาเป็นของ definition นั้นจริง ก่อนทำอะไรทั้งสิ้น
 */
class WorkflowDesignController extends Controller
{
    public function __construct(private readonly WorkflowDesignService $design) {}

    /** ภาพรวมทุกเวอร์ชัน พร้อมรายชื่อสิทธิ์ที่เลือกเป็นผู้รับผิดชอบขั้นได้ */
    public function index(ModuleRegistry $registry)
    {
        return response()->json([
            ...$this->design->overview(),
            /*
             * ส่งรายชื่อสิทธิ์พร้อมคำอธิบายไทยไปให้หน้าจอเลือก — ไม่ให้พิมพ์เอง
             * ผู้ดูแลไม่ควรต้องจำว่าชื่อสิทธิ์สะกดอย่างไร และการพิมพ์ผิดหนึ่งตัว
             * จะได้ขั้นตอนที่ไม่มีใครทำได้ ซึ่งกว่าจะรู้ก็ตอนมีเรื่องค้าง
             */
            'assignable_permissions' => collect($registry->get('saraban')->permissions())
                ->map(fn (string $label, string $name) => ['name' => $name, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    public function show(WorkflowVersion $version)
    {
        $this->assertBelongsToSaraban($version);

        return response()->json($this->design->detail($version));
    }

    /** สร้างฉบับร่างโดยคัดลอกจากเวอร์ชันที่ใช้งานอยู่ */
    public function storeDraft(Request $request)
    {
        try {
            $draft = $this->design->createDraft($request->user());
        } catch (WorkflowDesignException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->design->detail($draft), 201);
    }

    /** บันทึกลำดับขั้นของฉบับร่าง — เส้นทางถูกผูกใหม่ให้ทั้งชุดที่ฝั่ง server */
    public function updateDraft(Request $request, WorkflowVersion $version)
    {
        $this->assertBelongsToSaraban($version);

        $data = $request->validate([
            'steps' => ['required', 'array', 'min:1', 'max:'.WorkflowDesignService::MAX_STEPS],
            'steps.*.code' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'steps.*.name' => ['required', 'string', 'max:150'],
            'steps.*.assignee_permission' => ['required', 'string', 'max:150'],
        ]);

        try {
            $saved = $this->design->saveDraft($version, $data['steps'], $request->user());
        } catch (WorkflowDesignException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->design->detail($saved));
    }

    public function publish(Request $request, WorkflowVersion $version)
    {
        $this->assertBelongsToSaraban($version);

        try {
            $published = $this->design->publish($version, $request->user());
        } catch (WorkflowDesignException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->design->detail($published));
    }

    public function destroyDraft(Request $request, WorkflowVersion $version)
    {
        $this->assertBelongsToSaraban($version);

        try {
            $this->design->discardDraft($version, $request->user());
        } catch (WorkflowDesignException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'ลบฉบับร่างแล้ว']);
    }

    /**
     * กันไม่ให้ endpoint ของสารบรรณไปแตะสายงานของโมดูลอื่น
     *
     * `WorkflowVersion` เป็นตารางกลางที่โมดูลการลาและการลงเวลาใช้ร่วมกัน —
     * ถ้าไม่ตรวจ ผู้ที่มี `saraban.workflow.manage` จะแก้สายอนุมัติใบลาได้ผ่าน
     * การเดา id ซึ่งเป็นการข้ามขอบเขตโมดูลอย่างเงียบ ๆ
     */
    private function assertBelongsToSaraban(WorkflowVersion $version): void
    {
        abort_unless(
            $version->workflow_definition_id === $this->design->definition()->id,
            404,
        );
    }
}
