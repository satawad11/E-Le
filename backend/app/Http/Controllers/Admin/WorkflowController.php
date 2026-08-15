<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalStep;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Workflow\LeaveWorkflowProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkflowController extends Controller
{
    /**
     * Compute current fingerprint of approval steps configuration.
     */
    private function computeFingerprint($steps): string
    {
        return md5($steps->map(fn ($s) => $s->step_order.':'.($s->approver_type ?? 'ROLE').':'.($s->role_id ?? '0').':'.($s->specific_user_id ?? '0').':'.trim($s->name))->implode('|'));
    }

    public function index(Request $request)
    {
        $steps = ApprovalStep::with(['role', 'specificUser:id,name,email'])->orderBy('step_order', 'asc')->get();
        $fingerprint = $this->computeFingerprint($steps);

        if ($request->wantsJson() && $request->has('with_meta')) {
            return response()->json([
                'steps' => $steps,
                'fingerprint' => $fingerprint,
                'count' => $steps->count(),
            ]);
        }

        return response()->json($steps)->header('X-Workflow-Fingerprint', $fingerprint);
    }

    public function store(Request $request)
    {
        $request->validate([
            'steps' => 'required|array|min:1',
            'steps.*.name' => 'required|string|max:255',
            'steps.*.approver_type' => 'nullable|string|in:HR_OFFICER,REQUESTER_SUPERVISOR,DEPARTMENT_HEAD,DIVISION_DIRECTOR,DIRECTOR,ROLE,SPECIFIC_USER',
            'steps.*.role_id' => 'nullable|integer|exists:roles,id',
            'steps.*.specific_user_id' => 'nullable|integer|exists:users,id',
            'fingerprint' => 'nullable|string',
        ], [
            'steps.required' => 'กรุณาระบุขั้นตอนการอนุมัติอย่างน้อย 1 ขั้นตอน',
            'steps.min' => 'กรุณาระบุขั้นตอนการอนุมัติอย่างน้อย 1 ขั้นตอน',
            'steps.*.name.required' => 'กรุณากรอกชื่อขั้นตอนให้ครบทุกขั้นตอน',
            'steps.*.role_id.exists' => 'บทบาทที่เลือกไม่มีอยู่ในระบบ',
            'steps.*.specific_user_id.exists' => 'ผู้ใช้ที่ระบุไม่มีอยู่ในระบบ',
        ]);

        $stepDataList = array_values($request->steps);
        $roleIds = Role::pluck('id')->all();

        foreach ($stepDataList as $index => $step) {
            $name = trim($step['name'] ?? '');
            if (empty($name)) {
                throw ValidationException::withMessages([
                    "steps.{$index}.name" => ["กรุณากรอกชื่อขั้นตอนในลำดับที่ ".($index + 1)],
                ]);
            }

            $approverType = $step['approver_type'] ?? ApprovalStep::RESOLVER_ROLE;

            if ($approverType === ApprovalStep::RESOLVER_ROLE) {
                if (empty($step['role_id']) || ! in_array((int)$step['role_id'], $roleIds, true)) {
                    throw ValidationException::withMessages([
                        "steps.{$index}.role_id" => ["กรุณาเลือกบทบาทที่ถูกต้องในลำดับที่ ".($index + 1)],
                    ]);
                }
            } elseif ($approverType === ApprovalStep::RESOLVER_SPECIFIC_USER) {
                if (empty($step['specific_user_id']) || ! User::whereKey($step['specific_user_id'])->exists()) {
                    throw ValidationException::withMessages([
                        "steps.{$index}.specific_user_id" => ["กรุณาระบุผู้ใช้ที่ถูกต้องในลำดับที่ ".($index + 1)],
                    ]);
                }
            }
        }

        // Optimistic concurrency check
        $currentSteps = ApprovalStep::orderBy('step_order', 'asc')->get();
        $currentFingerprint = $this->computeFingerprint($currentSteps);

        if ($request->filled('fingerprint') && $request->fingerprint !== $currentFingerprint) {
            return response()->json([
                'message' => 'ข้อมูลลำดับการอนุมัติถูกแก้ไขโดยผู้ดูแลระบบท่านอื่นแล้ว กรุณารีเฟรชเพื่อโหลดข้อมูลล่าสุด',
                'conflict' => true,
                'current_steps' => ApprovalStep::with(['role', 'specificUser:id,name,email'])->orderBy('step_order', 'asc')->get(),
                'current_fingerprint' => $currentFingerprint,
            ], 409);
        }

        $savedSteps = DB::transaction(function () use ($stepDataList, $request) {
            ApprovalStep::query()->delete();

            $created = [];
            foreach ($stepDataList as $index => $stepData) {
                $approverType = $stepData['approver_type'] ?? ApprovalStep::RESOLVER_ROLE;
                $roleId = ! empty($stepData['role_id']) ? (int) $stepData['role_id'] : null;

                // Fallback default role for automatic resolvers to maintain backward DB safety
                if ($roleId === null && $approverType !== ApprovalStep::RESOLVER_SPECIFIC_USER) {
                    $roleId = Role::whereIn('name', ['Super Admin', 'Director', 'HR Head'])->value('id') ?? Role::first()?->id;
                }

                $created[] = ApprovalStep::create([
                    'role_id' => $roleId,
                    'name' => trim($stepData['name']),
                    'step_order' => $index + 1,
                    'approver_type' => $approverType,
                    'specific_user_id' => ! empty($stepData['specific_user_id']) ? (int) $stepData['specific_user_id'] : null,
                ]);
            }

            try {
                app(LeaveWorkflowProvisioner::class)->publishCurrentVersion();
            } catch (\Throwable $e) {
                report($e);
            }

            if (app()->bound(AuditService::class)) {
                try {
                    app(AuditService::class)->log(
                        'approval_workflow_updated',
                        'ApprovalStep',
                        null,
                        ['steps_count' => count($created), 'steps' => $created],
                        $request->user()
                    );
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return $created;
        }, 5);

        $freshSteps = ApprovalStep::with(['role', 'specificUser:id,name,email'])->orderBy('step_order', 'asc')->get();
        $newFingerprint = $this->computeFingerprint($freshSteps);

        return response()->json([
            'message' => 'บันทึกลำดับการอนุมัติเรียบร้อยแล้ว',
            'steps' => $freshSteps,
            'fingerprint' => $newFingerprint,
        ])->header('X-Workflow-Fingerprint', $newFingerprint);
    }
}
