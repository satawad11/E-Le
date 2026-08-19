<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalStep;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Workflow\ApproverResolverService;
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

    /**
     * ดูตัวอย่างว่า "ใครคือผู้อนุมัติจริง" ของแต่ละขั้น เมื่อผู้ยื่นเป็นคนนี้
     *
     * ผู้ดูแลตั้งค่าสายอนุมัติแล้วไม่มีทางรู้ว่าระบบจะไปหยิบใครมาจริง ๆ จนกว่าจะมี
     * คนยื่นใบลาแล้วติด 403 หน้านี้จึงตอบด้วย resolver ตัวเดียวกับที่ใช้ตัดสินสิทธิ์
     * ตอนกดอนุมัติ — ทดลองกับสายที่ยังไม่บันทึกก็ได้ โดยส่ง `steps` มาด้วย
     */
    public function preview(Request $request)
    {
        $request->validate([
            'requester_id' => 'required|integer|exists:users,id',
            'steps' => 'nullable|array',
            'steps.*.name' => 'nullable|string|max:255',
            'steps.*.approver_type' => 'nullable|string|in:HR_OFFICER,REQUESTER_SUPERVISOR,DEPARTMENT_HEAD,DIVISION_DIRECTOR,DIRECTOR,ROLE,SPECIFIC_USER',
            'steps.*.role_id' => 'nullable|integer|exists:roles,id',
            'steps.*.specific_user_id' => 'nullable|integer|exists:users,id',
        ], [
            'requester_id.required' => 'กรุณาเลือกผู้ยื่นคำขอที่ต้องการทดลอง',
            'requester_id.exists' => 'ไม่พบผู้ใช้ที่เลือก',
        ]);

        $requester = User::with('profile')->findOrFail($request->integer('requester_id'));
        $resolver = app(ApproverResolverService::class);

        // ไม่ส่ง steps มา = ดูตัวอย่างจากสายอนุมัติที่บันทึกไว้จริง
        $steps = $request->filled('steps')
            ? collect($request->steps)->values()->map(fn ($step, $index) => [
                'step_order' => $index + 1,
                'name' => trim($step['name'] ?? '') ?: 'ขั้นตอนที่ '.($index + 1),
                'approver_type' => $step['approver_type'] ?? ApprovalStep::RESOLVER_ROLE,
                'role_id' => ! empty($step['role_id']) ? (int) $step['role_id'] : null,
                'specific_user_id' => ! empty($step['specific_user_id']) ? (int) $step['specific_user_id'] : null,
                'permission_name' => $step['permission_name'] ?? null,
            ])
            : ApprovalStep::orderBy('step_order')->get()->map(fn ($step) => [
                'step_order' => $step->step_order,
                'name' => $step->name,
                'approver_type' => $step->approver_type ?: ApprovalStep::RESOLVER_ROLE,
                'role_id' => $step->role_id,
                'specific_user_id' => $step->specific_user_id,
                'permission_name' => $step->permission_name,
            ]);

        $resolved = $steps->map(function (array $step) use ($resolver, $requester) {
            $eligibleIds = $resolver->resolveEligibleApproverIds($requester, $step);

            // ห้ามอนุมัติใบลาของตนเอง — ถ้าผู้ยื่นติดมาด้วยต้องไม่นับเป็นผู้อนุมัติที่ใช้ได้จริง
            $selfOnly = $eligibleIds === [$requester->id];
            $usableIds = array_values(array_diff($eligibleIds, [$requester->id]));

            $approvers = User::with('profile')->whereIn('id', $usableIds)->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->full_name_with_title ?? $user->name,
                    'position' => $user->profile?->position,
                ])->values();

            $warning = match (true) {
                $selfOnly => 'ผู้อนุมัติที่ระบบหาได้คือผู้ยื่นเอง ซึ่งอนุมัติใบลาของตนเองไม่ได้',
                $approvers->isEmpty() => 'ไม่พบผู้มีสิทธิ์อนุมัติในขั้นตอนนี้ ใบลาของผู้ยื่นรายนี้จะค้างอยู่ที่ขั้นนี้',
                default => null,
            };

            return [
                'step_order' => $step['step_order'],
                'name' => $step['name'],
                'approver_type' => $step['approver_type'],
                'approvers' => $approvers,
                'warning' => $warning,
            ];
        })->values();

        return response()->json([
            'requester' => [
                'id' => $requester->id,
                'name' => $requester->full_name_with_title ?? $requester->name,
                'position' => $requester->profile?->position,
            ],
            'steps' => $resolved,
        ]);
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
                    "steps.{$index}.name" => ['กรุณากรอกชื่อขั้นตอนในลำดับที่ '.($index + 1)],
                ]);
            }

            $approverType = $step['approver_type'] ?? ApprovalStep::RESOLVER_ROLE;

            if ($approverType === ApprovalStep::RESOLVER_ROLE) {
                if (empty($step['role_id']) || ! in_array((int) $step['role_id'], $roleIds, true)) {
                    throw ValidationException::withMessages([
                        "steps.{$index}.role_id" => ['กรุณาเลือกบทบาทที่ถูกต้องในลำดับที่ '.($index + 1)],
                    ]);
                }
            } elseif ($approverType === ApprovalStep::RESOLVER_SPECIFIC_USER) {
                if (empty($step['specific_user_id']) || ! User::whereKey($step['specific_user_id'])->exists()) {
                    throw ValidationException::withMessages([
                        "steps.{$index}.specific_user_id" => ['กรุณาระบุผู้ใช้ที่ถูกต้องในลำดับที่ '.($index + 1)],
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
                /*
                 * เก็บ role เฉพาะที่ผู้ดูแลเลือกไว้จริงเท่านั้น
                 *
                 * เดิมขั้นตอนที่หาตัวผู้อนุมัติอัตโนมัติ (หัวหน้าแผนก/รอง ผอ./ผอ./HR)
                 * ถูกเติม role Super Admin/Director/HR Head ให้เงียบ ๆ เมื่อไม่ได้เลือก role
                 * ผลคือสิทธิ์อนุมัติรั่วไปถึงผู้ถือ role เหล่านั้นทั้งที่ไม่ได้ตั้งใจ
                 * และหน้าจอก็แสดงไม่ตรงกับสิ่งที่ระบบใช้จริง
                 *
                 * ขั้นตอนอัตโนมัติมีทางถอยของตัวเองอยู่แล้วใน ApproverResolverService
                 * (permission + ชื่อบทบาทมาตรฐาน) เมื่อหาตัวจากผังองค์กรไม่พบ
                 */
                $roleId = ! empty($stepData['role_id']) ? (int) $stepData['role_id'] : null;

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
