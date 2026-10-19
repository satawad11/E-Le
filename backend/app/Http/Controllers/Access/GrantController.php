<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\Authz\PermissionChangeRequest;
use App\Models\Authz\PermissionOverride;
use App\Models\Authz\RoleGrant;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\Access\AccessGrantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API มอบ/ถอนสิทธิ์และคำขอเปลี่ยนสิทธิ์ (CORE-04 · R05-4) — กฎกลางอยู่ที่ AccessGrantService
 *
 * - มอบ/ถอน: `access.grant.manage` (+ not_own กับบัญชีเป้าหมาย) · สิทธิ์ความเสี่ยงสูงตอบ 202 พร้อมคำขอรอคนที่สอง
 * - ยืนยัน/ปฏิเสธคำขอ: `access.change.approve` + maker_checker (engine) — ผู้ยื่นยืนยันเองไม่ได้
 */
class GrantController extends Controller
{
    public function __construct(private readonly AccessGrantService $grants) {}

    public function grantRole(Request $request, User $user): JsonResponse
    {
        $this->authorizeResource('access.grant.manage', $user);

        $data = $request->validate([
            'template_code' => ['required', 'string', 'max:100'],
            'order_number' => ['required', 'string', 'max:120'],
            'effective_from' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after_or_equal:effective_from', 'after_or_equal:today'],
            'org_unit_uuids' => ['nullable', 'array', 'max:20'],
            'org_unit_uuids.*' => ['uuid', 'exists:org_units,uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $data['org_unit_ids'] = OrgUnit::whereIn('uuid', $data['org_unit_uuids'] ?? [])->pluck('id')->all();

        return $this->respond($this->grants->grantRole($request->user(), $user, $data));
    }

    public function endRoleGrant(Request $request, RoleGrant $grant): JsonResponse
    {
        $this->authorizeResource('access.grant.manage', $grant->user);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json($this->presentGrant($this->grants->endRoleGrant($request->user(), $grant, $data['reason'])));
    }

    public function addOverride(Request $request, User $user): JsonResponse
    {
        $this->authorizeResource('access.grant.manage', $user);

        $data = $request->validate([
            'permission' => ['required', 'string', 'max:150'],
            'effect' => ['required', Rule::in([PermissionOverride::EFFECT_ALLOW, PermissionOverride::EFFECT_DENY])],
            'scope' => ['required_if:effect,allow', 'nullable', Rule::in(['own', 'unit', 'subtree', 'all', 'assigned'])],
            'org_unit_uuid' => ['nullable', 'uuid', 'exists:org_units,uuid'],
            'reason' => ['required', 'string', 'max:500'],
            'expires_at' => ['required_if:effect,allow', 'nullable', 'date', 'after_or_equal:today'],
        ]);
        $data['org_unit_id'] = isset($data['org_unit_uuid']) ? OrgUnit::where('uuid', $data['org_unit_uuid'])->value('id') : null;

        return $this->respond($this->grants->addOverride($request->user(), $user, $data));
    }

    public function endOverride(Request $request, PermissionOverride $override): JsonResponse
    {
        $this->authorizeResource('access.grant.manage', $override->user);
        $override = $this->grants->endOverride($request->user(), $override);

        return response()->json(['uuid' => $override->uuid, 'ended_at' => $override->ended_at?->toIso8601String()]);
    }

    public function changeRequests(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(PermissionChangeRequest::STATUSES)]]);

        $rows = PermissionChangeRequest::with(['requester.personnel', 'approver.personnel'])
            ->where('status', $data['status'] ?? 'pending')
            ->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (PermissionChangeRequest $r) => $this->presentRequest($r, $request->user()))->values()]);
    }

    public function approve(Request $request, PermissionChangeRequest $changeRequest): JsonResponse
    {
        $this->authorizeResource('access.change.approve', $changeRequest);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return response()->json($this->presentRequest($this->grants->approve($request->user(), $changeRequest, $data['note'] ?? null), $request->user()));
    }

    public function reject(Request $request, PermissionChangeRequest $changeRequest): JsonResponse
    {
        $this->authorizeResource('access.change.approve', $changeRequest);
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        return response()->json($this->presentRequest($this->grants->reject($request->user(), $changeRequest, $data['note']), $request->user()));
    }

    public function cancel(Request $request, PermissionChangeRequest $changeRequest): JsonResponse
    {
        return response()->json($this->presentRequest($this->grants->cancel($request->user(), $changeRequest), $request->user()));
    }

    // ------------------------------------------------------------------ ภายใน

    private function respond(RoleGrant|PermissionOverride|PermissionChangeRequest $result): JsonResponse
    {
        return match (true) {
            $result instanceof PermissionChangeRequest => response()->json([
                'message' => 'สิทธิ์ความเสี่ยงสูง — ส่งคำขอให้ผู้ดูแลคนที่สองยืนยันแล้ว ยังไม่มีผลจนกว่าจะได้รับการยืนยัน',
                'change_request' => $this->presentRequest($result->load('requester.personnel'), null),
            ], 202),
            $result instanceof RoleGrant => response()->json($this->presentGrant($result), 201),
            default => response()->json(['uuid' => $result->uuid, 'effect' => $result->effect, 'scope' => $result->scope,
                'expires_at' => $result->expires_at?->toDateString()], 201),
        };
    }

    /** @return array<string, mixed> */
    private function presentGrant(RoleGrant $grant): array
    {
        $grant->loadMissing('template:id,code,name');

        return [
            'uuid' => $grant->uuid,
            'template' => $grant->template?->only(['code', 'name']),
            'order_number' => $grant->order_number,
            'effective_from' => $grant->effective_from?->toDateString(),
            'expires_at' => $grant->expires_at?->toDateString(),
            'ended_at' => $grant->ended_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentRequest(PermissionChangeRequest $r, ?User $viewer): array
    {
        $target = User::with('personnel')->find($r->target['user_id'] ?? null);

        return [
            'uuid' => $r->uuid,
            'status' => $r->status,
            'diff' => $r->diff,
            'reason' => $r->reason,
            'target' => $target ? ['uuid' => $target->uuid, 'name' => $target->personnel?->full_name ?? $target->username] : null,
            'requested_by' => $r->requester?->personnel?->full_name ?? $r->requester?->username,
            'approved_by' => $r->approver?->personnel?->full_name ?? $r->approver?->username,
            'decided_at' => $r->decided_at?->toIso8601String(),
            'decision_note' => $r->decision_note,
            'created_at' => $r->created_at?->toIso8601String(),
            'is_mine' => $viewer !== null && $r->requested_by === $viewer->id,
        ];
    }
}
