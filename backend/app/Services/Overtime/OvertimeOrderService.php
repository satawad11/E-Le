<?php

namespace App\Services\Overtime;

use App\Models\Overtime\OvertimeAssignment;
use App\Models\Overtime\OvertimeOrder;
use App\Models\User;
use App\Models\Workflow\WorkflowTransition;
use App\Services\AuditLogger;
use App\Services\NotificationCenter\NotificationCenter;
use App\Services\OrgScopeResolver;
use App\Services\Workflow\OvertimeOrderWorkflowProvisioner;
use App\Services\Workflow\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OvertimeOrderService
{
    public function __construct(
        private readonly OrgScopeResolver $scopes,
        private readonly OvertimeOrderWorkflowProvisioner $provisioner,
        private readonly WorkflowEngine $workflow,
        private readonly AuditLogger $audit,
        private readonly NotificationCenter $notifications,
    ) {}

    public function create(User $actor, array $data): OvertimeOrder
    {
        $orgUnitId = isset($data['org_unit_id']) ? (int) $data['org_unit_id'] : $this->scopes->homeOrgUnitId($actor);
        if (! $actor->can('overtime.operation.execute') || ! $this->scopes->canSeeOrgUnit($actor, 'overtime.operation.execute', $orgUnitId, OrgScopeResolver::UNIT)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $data, $orgUnitId) {
            $order = OvertimeOrder::create([
                'order_number' => $data['order_number'] ?? null,
                'title' => $data['title'], 'description' => $data['description'] ?? null,
                'org_unit_id' => $orgUnitId, 'timezone' => $data['timezone'] ?? 'Asia/Bangkok',
                'scheduled_start' => $data['scheduled_start'], 'scheduled_end' => $data['scheduled_end'],
                'status' => 'pending', 'created_by' => $actor->id, 'submitted_at' => now(),
            ]);
            $this->provisioner->start($order);
            $this->audit->log('overtime.order.created', $order, null, null, $order->toArray(), null, $actor->id);
            // R02-11: ยังไม่มี PermissionEngine ให้ค้นผู้ถือสิทธิ์ (deny by default) — R-04/R-08 แทนด้วยผู้รับตามสิทธิ์และ scope
            $reviewers = collect()
                ->filter(fn (User $reviewer) => $this->scopes->canSeeOrgUnit(
                    $reviewer, 'overtime.management.review', $orgUnitId, OrgScopeResolver::ORGANIZATION
                ));
            $this->notifications->emit('overtime.approval', $reviewers, $order, ['status' => 'pending'], $actor);

            return $order;
        }, 5);
    }

    public function decide(OvertimeOrder $order, User $actor, string $action, array $approvedWindow = [], ?string $comment = null): OvertimeOrder
    {
        $this->authorizeReviewer($order, $actor);

        return DB::transaction(function () use ($order, $actor, $action, $approvedWindow, $comment) {
            $order = OvertimeOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            /*
             * เดิมคืนคำสั่งเดิมเงียบ ๆ ทำให้ผู้ใช้ได้ 200 พร้อมข้อความ
             * "อนุมัติคำสั่ง OT แล้ว" ทั้งที่ไม่มีอะไรเกิดขึ้น เช่นกดอนุมัติ
             * คำสั่งที่ถูกปฏิเสธไปแล้ว หรือสองคนกดพร้อมกัน
             */
            if ($order->status !== 'pending') {
                throw new \DomainException('คำสั่ง OT นี้ถูกพิจารณาไปแล้ว ไม่สามารถพิจารณาซ้ำได้');
            }
            $instance = $this->workflow->instanceFor($order) ?? throw new \RuntimeException('ไม่พบ workflow ของคำสั่ง OT');
            $outcome = $this->workflow->perform($instance, $action, $actor, $comment);

            /*
             * สายอนุมัติมีสองขั้น (อนุญาต → อนุมัติ) การกด "อนุญาต" ในขั้นแรก
             * ยังไม่ทำให้คำสั่งใช้งานได้ ต้องคงสถานะ `pending` ไว้จนกว่าจะจบสาย
             * มิฉะนั้นจะลงเวลา OT ได้ทั้งที่ ผอ. ยังไม่อนุมัติ
             *
             * ช่วงเวลาที่ผู้อนุญาตขั้นแรกปรับไว้ถูกบันทึกเป็นข้อเสนอ
             * เพื่อให้ผู้อนุมัติขั้นสุดท้ายเห็นและใช้เป็นค่าตั้งต้น
             */
            if ($action === WorkflowTransition::APPROVE && ! $outcome['final']) {
                if ($approvedWindow !== []) {
                    $order->update(array_filter([
                        'approved_start' => $approvedWindow['approved_start'] ?? null,
                        'approved_end' => $approvedWindow['approved_end'] ?? null,
                    ]));
                }
                $this->audit->log('overtime.order.permitted', $order, null, null, [
                    'step' => $outcome['task']->step?->code,
                ], $comment, $actor->id);

                $this->notifications->emit('overtime.approval', [$order->creator], $order, [
                    'status' => 'pending',
                ], $actor);

                return $order->refresh();
            }

            if ($action === WorkflowTransition::APPROVE) {
                $start = $approvedWindow['approved_start'] ?? $order->scheduled_start;
                $end = $approvedWindow['approved_end'] ?? $order->scheduled_end;
                $start = CarbonImmutable::parse($start, $order->timezone);
                $end = CarbonImmutable::parse($end, $order->timezone);
                if ($end->lte($start)) {
                    throw ValidationException::withMessages(['approved_end' => 'เวลาสิ้นสุดที่อนุมัติต้องอยู่หลังเวลาเริ่ม']);
                }
                $order->update(['status' => 'approved', 'approved_start' => $start, 'approved_end' => $end, 'approved_at' => now()]);
                $this->audit->log('overtime.order.approved', $order, null, null, [
                    'approved_start' => $start, 'approved_end' => $end,
                ], $comment, $actor->id);
            } else {
                $order->update(['status' => 'rejected']);
                $this->audit->log('overtime.order.rejected', $order, null, null, null, $comment, $actor->id);
            }

            $this->notifications->emit('overtime.approval', [$order->creator], $order, [
                'status' => $order->status,
            ], $actor);

            return $order->refresh();
        }, 5);
    }

    /** @param array<int,int> $userIds */
    public function assign(OvertimeOrder $order, User $actor, array $userIds): OvertimeOrder
    {
        if ($order->status !== 'approved') {
            throw new \DomainException('คำสั่ง OT ต้องได้รับอนุมัติก่อนมอบหมายบุคลากร');
        }
        $this->authorizeReviewer($order, $actor);
        $allowedUnits = $order->org_unit_id === null ? null : $this->scopes->subtreeIds((int) $order->org_unit_id);
        $users = User::whereIn('id', $userIds)
            ->when($allowedUnits !== null, fn ($query) => $query->whereIn('org_unit_id', $allowedUnits))
            ->get();
        if ($users->count() !== count(array_unique($userIds))) {
            throw new AuthorizationException('มีบุคลากรอยู่นอกขอบเขตคำสั่ง OT');
        }

        DB::transaction(function () use ($order, $actor, $users) {
            foreach ($users as $user) {
                OvertimeAssignment::firstOrCreate(
                    ['ot_order_id' => $order->id, 'user_id' => $user->id],
                    ['assigned_by' => $actor->id, 'status' => 'assigned', 'assigned_at' => now()],
                );
            }
            $this->audit->log('overtime.assignments.updated', $order, null, null, ['user_ids' => $users->pluck('id')->all()], null, $actor->id);
        }, 5);

        return $order->load('assignments.user');
    }

    public function authorizeReviewer(OvertimeOrder $order, User $actor): void
    {
        if (! $actor->can('overtime.management.review')
            || ! $this->scopes->canSeeOrgUnit($actor, 'overtime.management.review', $order->org_unit_id, OrgScopeResolver::ORGANIZATION)) {
            throw new AuthorizationException;
        }
    }
}
