<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalStep;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Workflow\ApprovalChainReadiness;
use App\Services\Workflow\ApproverResolverService;
use App\Services\Workflow\LeaveWorkflowProvisioner;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * สายที่กำลังจัดการอยู่ — ค่าเริ่มต้นคือสายของใบลา เพื่อให้ผู้เรียกเดิมได้ผลเท่าเดิม
     */
    private function chainOf(Request $request): string
    {
        $chain = (string) ($request->input('chain') ?? ApprovalStep::CHAIN_LEAVE);

        abort_unless(in_array($chain, ApprovalStep::CHAINS, true), 422, 'ไม่รู้จักสายอนุมัติที่ระบุ');

        return $chain;
    }

    /** @return Collection<int,ApprovalStep> */
    private function stepsOf(string $chain)
    {
        return ApprovalStep::with(['role', 'specificUser:id,name,email'])
            ->chain($chain)->orderBy('step_order', 'asc')->get();
    }

    public function index(Request $request)
    {
        $chain = $this->chainOf($request);
        $steps = $this->stepsOf($chain);
        $fingerprint = $this->computeFingerprint($steps);

        if ($request->wantsJson() && $request->has('with_meta')) {
            return response()->json([
                'chain' => $chain,
                'steps' => $steps,
                'fingerprint' => $fingerprint,
                'count' => $steps->count(),
                // สายยกเลิกที่ยังไม่ได้ตั้งค่า = ใช้สายของใบลา
                'inherits_leave_chain' => $chain === ApprovalStep::CHAIN_CANCELLATION && $steps->isEmpty(),
            ]);
        }

        return response()->json($steps)
            ->header('X-Workflow-Fingerprint', $fingerprint)
            ->header('X-Workflow-Chain', $chain);
    }

    /**
     * ขั้นตอนที่จะนำไปคำนวณ — จากที่ส่งมา (ยังไม่บันทึก) หรือจากสายที่บันทึกไว้จริง
     *
     * สายยกเลิกที่ยังไม่ได้ตั้งค่าจะได้สายของใบลา ตามค่าเริ่มต้นของระบบ
     *
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function normalizedSteps(Request $request, string $chain)
    {
        if ($request->filled('steps')) {
            return collect($request->steps)->values()->map(fn ($step, $index) => [
                'step_order' => $index + 1,
                'name' => trim($step['name'] ?? '') ?: 'ขั้นตอนที่ '.($index + 1),
                'approver_type' => $step['approver_type'] ?? ApprovalStep::RESOLVER_ROLE,
                'role_id' => ! empty($step['role_id']) ? (int) $step['role_id'] : null,
                'specific_user_id' => ! empty($step['specific_user_id']) ? (int) $step['specific_user_id'] : null,
                'permission_name' => $step['permission_name'] ?? null,
            ]);
        }

        return ApprovalStep::stepsFor($chain)->map(fn ($step) => [
            'step_order' => $step->step_order,
            'name' => $step->name,
            'approver_type' => $step->approver_type ?: ApprovalStep::RESOLVER_ROLE,
            'role_id' => $step->role_id,
            'specific_user_id' => $step->specific_user_id,
            'permission_name' => $step->permission_name,
        ])->values();
    }

    /**
     * ตรวจความพร้อมของข้อมูลบุคลากรทั้งองค์กรเทียบกับสายอนุมัตินี้
     *
     * `preview` ตอบได้ทีละคน ผู้ดูแลจึงไม่มีทางรู้ว่าทั้งองค์กรมีใครบ้างที่จะติด
     * จนกว่าจะมีคนยื่นจริงแล้วใบค้าง — หน้านี้ตอบคำถามนั้นก่อนเปลี่ยนสาย
     *
     * ส่ง `steps` มาด้วยได้เพื่อตรวจสายที่กำลังแก้อยู่แต่ยังไม่บันทึก
     */
    public function readiness(Request $request, ApprovalChainReadiness $readiness)
    {
        $request->validate([
            'steps' => 'nullable|array',
            'steps.*.name' => 'nullable|string|max:255',
            'steps.*.approver_type' => 'nullable|string|in:HR_OFFICER,REQUESTER_SUPERVISOR,DEPARTMENT_HEAD,DIVISION_DIRECTOR,DIRECTOR,ROLE,SPECIFIC_USER',
            'steps.*.role_id' => 'nullable|integer|exists:roles,id',
            'steps.*.specific_user_id' => 'nullable|integer|exists:users,id',
        ]);

        $chain = $this->chainOf($request);
        $steps = $this->normalizedSteps($request, $chain);

        return response()->json([
            'chain' => $chain,
            'inherits_leave_chain' => $chain === ApprovalStep::CHAIN_CANCELLATION
                && ! $request->filled('steps')
                && ! ApprovalStep::cancellationChainIsConfigured(),
            ...$readiness->inspect($steps),
        ]);
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

        $chain = $this->chainOf($request);
        $requester = User::with('profile')->findOrFail($request->integer('requester_id'));
        $resolver = app(ApproverResolverService::class);
        $steps = $this->normalizedSteps($request, $chain);

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
            'chain' => $chain,
            'inherits_leave_chain' => $chain === ApprovalStep::CHAIN_CANCELLATION
                && ! $request->filled('steps')
                && ! ApprovalStep::cancellationChainIsConfigured(),
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
        $chain = $this->chainOf($request);

        /*
         * สายของใบลาต้องมีอย่างน้อยหนึ่งขั้นเสมอ — ไม่มีขั้นตอน = ไม่มีใครอนุมัติใบลาได้
         * ส่วนสายยกเลิกส่งมาว่างได้ ซึ่งแปลว่า "ใช้สายเดียวกับใบลา" (ค่าเริ่มต้นของระบบ)
         */
        $request->validate([
            'steps' => ($chain === ApprovalStep::CHAIN_LEAVE ? 'required|array|min:1' : 'present|array'),
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

        $stepDataList = array_values($request->input('steps', []));
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

        // Optimistic concurrency check — เทียบเฉพาะสายที่กำลังบันทึก
        $currentSteps = $this->stepsOf($chain);
        $currentFingerprint = $this->computeFingerprint($currentSteps);

        if ($request->filled('fingerprint') && $request->fingerprint !== $currentFingerprint) {
            return response()->json([
                'message' => 'ข้อมูลลำดับการอนุมัติถูกแก้ไขโดยผู้ดูแลระบบท่านอื่นแล้ว กรุณารีเฟรชเพื่อโหลดข้อมูลล่าสุด',
                'conflict' => true,
                'chain' => $chain,
                'current_steps' => $currentSteps,
                'current_fingerprint' => $currentFingerprint,
            ], 409);
        }

        $savedSteps = DB::transaction(function () use ($stepDataList, $request, $chain, $currentSteps) {
            ApprovalStep::query()->chain($chain)->delete();

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
                    'chain' => $chain,
                    'role_id' => $roleId,
                    'name' => trim($stepData['name']),
                    'step_order' => $index + 1,
                    'approver_type' => $approverType,
                    'specific_user_id' => ! empty($stepData['specific_user_id']) ? (int) $stepData['specific_user_id'] : null,
                ]);
            }

            // ออกเวอร์ชันใหม่ให้ engine ทันทีที่ผู้ดูแลแก้สาย — เฉพาะสายของใบลา
            if ($chain === ApprovalStep::CHAIN_LEAVE) {
                try {
                    app(LeaveWorkflowProvisioner::class)->publishCurrentVersion();
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            /*
             * บันทึกการแก้สายอนุมัติลงประวัติกลาง
             *
             * เดิมโค้ดตรงนี้เรียก `AuditService` ซึ่งไม่มีอยู่จริงในระบบ (ชื่อจริงคือ `AuditLogger`)
             * และถูกห่อด้วย `app()->bound()` ไว้ การแก้สายอนุมัติจึงไม่เคยถูกบันทึกเลยแม้แต่ครั้งเดียว
             * ทั้งที่เป็นการเปลี่ยนกติกาว่าใครอนุมัติใบลาได้ — ต้องตรวจย้อนหลังได้ตาม docs/12
             */
            try {
                app(AuditLogger::class)->log(
                    action: 'approval_workflow_updated',
                    resource: 'ApprovalStep',
                    before: ['chain' => $chain, 'steps' => $currentSteps->map->only(['step_order', 'name', 'approver_type', 'role_id', 'specific_user_id'])->all()],
                    after: ['chain' => $chain, 'steps' => array_map(fn (ApprovalStep $step) => $step->only(['step_order', 'name', 'approver_type', 'role_id', 'specific_user_id']), $created)],
                    actorId: $request->user()?->id,
                );
            } catch (\Throwable $e) {
                // ประวัติล้มต้องไม่ทำให้การบันทึกสายอนุมัติล้มตาม
                report($e);
            }

            return $created;
        }, 5);

        $freshSteps = $this->stepsOf($chain);
        $newFingerprint = $this->computeFingerprint($freshSteps);
        $inherits = $chain === ApprovalStep::CHAIN_CANCELLATION && $freshSteps->isEmpty();

        return response()->json([
            'message' => $inherits
                ? 'ตั้งค่าให้คำขอยกเลิกใช้สายอนุมัติเดียวกับใบลาเรียบร้อยแล้ว'
                : 'บันทึกลำดับการอนุมัติเรียบร้อยแล้ว',
            'chain' => $chain,
            'steps' => $freshSteps,
            'fingerprint' => $newFingerprint,
            'inherits_leave_chain' => $inherits,
        ])->header('X-Workflow-Fingerprint', $newFingerprint)->header('X-Workflow-Chain', $chain);
    }
}
