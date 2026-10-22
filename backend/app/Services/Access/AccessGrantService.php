<?php

namespace App\Services\Access;

use App\Authz\PermissionEngine;
use App\Models\Assignment;
use App\Models\Authz\Permission;
use App\Models\Authz\PermissionChangeRequest;
use App\Models\Authz\PermissionOverride;
use App\Models\Authz\PermissionTemplate;
use App\Models\Authz\RoleGrant;
use App\Models\Authz\TemplatePermission;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * มอบ/ถอน role grant และ override รายบุคคล (CORE-04 · R05-4)
 *
 * - `not_own`: มอบหรือถอนสิทธิ์ของตนเองไม่ได้ · ยืนยันคำขอที่ให้สิทธิ์แก่ตนเองไม่ได้
 * - `grant_ceiling`: ผู้มอบต้องถือทุก permission ที่จะให้ใน scope ≥ และครอบคลุมหน่วยที่ให้ (PermissionEngine::canGrant)
 *   ตรวจทั้งตอนยื่นและตอนยืนยัน (สิทธิ์ของผู้ยื่นอาจเปลี่ยนระหว่างรอ)
 * - `two_person`: ชุดสิทธิ์/permission ความเสี่ยงสูง (PermissionEngine::requiresSecondPerson) ไม่มีผลทันที
 *   → `permission_change_requests` รอผู้ถือ `access.change.approve` คนที่สอง (`maker_checker` — ผู้ยื่นยืนยันเองไม่ได้)
 * - การถอนสิทธิ์และ deny ลดอำนาจ จึงมีผลทันทีไม่ต้องรอคนที่สอง
 * - role grant ต้องมีเลขคำสั่งและวันหมดอายุ · override allow ต้องมีวันหมดอายุ (PROJECT_RULES ข้อ 9)
 * ทุกการเปลี่ยนบันทึก `permission_audit_logs`
 */
class AccessGrantService
{
    public function __construct(
        private readonly PermissionEngine $engine,
        private readonly PermissionAudit $audit,
        private readonly AccountLifecycleService $lifecycle,
    ) {}

    // ------------------------------------------------------------------ role grants

    /**
     * @param  array{template_code: string, order_number: string, effective_from: string, expires_at: string, org_unit_ids?: array<int, int>, reason?: string|null}  $data
     */
    public function grantRole(User $actor, User $target, array $data): RoleGrant|PermissionChangeRequest
    {
        $this->guardTarget($actor, $target);

        $template = PermissionTemplate::where('code', $data['template_code'])->whereNull('retired_at')->first();
        if ($template === null) {
            throw ValidationException::withMessages(['template_code' => 'ไม่มีชุดสิทธิ์นี้หรือเลิกใช้แล้ว']);
        }
        if ($template->kind !== 'functional') {
            throw ValidationException::withMessages(['template_code' => 'มอบได้เฉพาะชุดสิทธิ์เชิงหน้าที่ — ชุดสิทธิ์ตามตำแหน่งได้จากคำสั่งแต่งตั้ง']);
        }

        $diff = [
            'action' => 'role_grant.create',
            'template_code' => $template->code,
            'template_name' => $template->name,
            'order_number' => $data['order_number'],
            'effective_from' => $data['effective_from'],
            'expires_at' => $data['expires_at'],
            'org_unit_ids' => array_values(array_map('intval', $data['org_unit_ids'] ?? [])),
        ];

        $permissions = $this->templatePermissions($template);
        $this->assertCeiling($actor, $target, $permissions, $diff['org_unit_ids']);

        if ($this->engine->requiresSecondPerson(array_keys($permissions))) {
            return $this->openRequest($actor, $target, $diff, $data['reason'] ?? null);
        }

        return DB::transaction(fn () => $this->applyRoleGrant($actor->id, null, $target, $diff, null));
    }

    public function endRoleGrant(User $actor, RoleGrant $grant, string $reason): RoleGrant
    {
        $this->guardTarget($actor, $grant->user);
        if ($grant->ended_at !== null) {
            throw ValidationException::withMessages(['grant' => 'ชุดสิทธิ์นี้ถูกถอนแล้ว']);
        }

        $grant->forceFill(['ended_at' => now(), 'ended_by' => $actor->id, 'end_reason' => $reason])->save();
        $this->audit->log('role_grant.ended', $grant, ['ended_at' => null], ['ended_at' => now()->toIso8601String(), 'end_reason' => $reason], $actor->id);

        return $grant;
    }

    // ------------------------------------------------------------------ overrides

    /**
     * @param  array{permission: string, effect: string, scope?: string|null, org_unit_id?: int|null, reason: string, expires_at?: string|null}  $data
     */
    public function addOverride(User $actor, User $target, array $data): PermissionOverride|PermissionChangeRequest
    {
        $this->guardTarget($actor, $target);

        $permission = Permission::where('name', $data['permission'])->whereNull('retired_at')->first();
        if ($permission === null) {
            throw ValidationException::withMessages(['permission' => 'ไม่มีสิทธิ์นี้ใน manifest']);
        }

        $diff = [
            'action' => 'permission_override.create',
            'permission' => $permission->name,
            'effect' => $data['effect'],
            'scope' => $data['effect'] === PermissionOverride::EFFECT_ALLOW ? $data['scope'] : null,
            'org_unit_id' => $data['org_unit_id'] ?? null,
            'reason' => $data['reason'],
            'expires_at' => $data['expires_at'] ?? null,
        ];

        if ($diff['effect'] === PermissionOverride::EFFECT_ALLOW) {
            if ($diff['expires_at'] === null) {
                throw ValidationException::withMessages(['expires_at' => 'สิทธิ์รายบุคคลแบบอนุญาตต้องมีวันหมดอายุ']);
            }
            if (! in_array($diff['scope'], $permission->allowed_scopes ?? [], true)) {
                throw ValidationException::withMessages(['scope' => "{$permission->name} ไม่รองรับขอบเขต {$diff['scope']}"]);
            }

            $this->assertCeiling($actor, $target, [$permission->name => $diff['scope']], array_filter([$diff['org_unit_id']]));

            if ($this->engine->requiresSecondPerson($permission->name)) {
                return $this->openRequest($actor, $target, $diff, $data['reason']);
            }
        }

        return DB::transaction(fn () => $this->applyOverride($actor->id, null, $target, $diff, null));
    }

    public function endOverride(User $actor, PermissionOverride $override): PermissionOverride
    {
        $this->guardTarget($actor, $override->user);
        if ($override->ended_at !== null) {
            throw ValidationException::withMessages(['override' => 'สิทธิ์รายบุคคลนี้สิ้นสุดแล้ว']);
        }

        $override->forceFill(['ended_at' => now()])->save();
        $this->audit->log('permission_override.ended', $override, ['ended_at' => null], ['ended_at' => now()->toIso8601String()], $actor->id);

        return $override;
    }

    // ------------------------------------------------------------------ รีเซ็ต MFA ของบัญชีที่ถูกบังคับใช้ MFA (two_person)

    /** ผู้ดูแลยื่นคำขอรีเซ็ต MFA — มีผลเมื่อผู้ยืนยันคนที่สองอนุมัติ (แก้ไขหลังตรวจ R-05 ข้อ 2) */
    public function requestMfaReset(User $actor, User $target, string $note): PermissionChangeRequest
    {
        $this->guardTarget($actor, $target);

        return $this->openRequest($actor, $target, ['action' => 'account.mfa_reset', 'note' => $note], $note);
    }

    // ------------------------------------------------------------------ คำขอเปลี่ยนสิทธิ์ (two_person)

    /** ผู้ยืนยันคนที่สอง — controller ตรวจ access.change.approve + maker_checker ผ่าน engine แล้ว */
    public function approve(User $approver, PermissionChangeRequest $request, ?string $note): PermissionChangeRequest
    {
        $this->assertPending($request);
        $target = User::find($request->target['user_id'] ?? null);
        if ($target === null || $target->status === User::STATUS_SEPARATED) {
            throw ValidationException::withMessages(['request' => 'บัญชีเป้าหมายไม่อยู่ในสถานะที่รับสิทธิ์ได้']);
        }
        if ($target->is($approver)) {
            throw new HttpException(403, 'ยืนยันคำขอที่ให้สิทธิ์แก่ตนเองไม่ได้ (not_own)');
        }

        $requester = $request->requester;
        $diff = $request->diff;

        if ($diff['action'] === 'account.mfa_reset') {
            if (! $this->engine->allows($requester, 'access.account.manage', $target)) {
                throw new HttpException(403, 'ผู้ยื่นคำขอไม่มีสิทธิ์จัดการบัญชีนี้แล้ว — ปฏิเสธคำขอนี้');
            }

            return DB::transaction(function () use ($approver, $request, $target, $requester, $diff, $note) {
                $locked = PermissionChangeRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
                $this->assertPending($locked);
                $this->lifecycle->resetMfa($target, $approver, (string) $diff['note'], $requester, $locked->id);
                $locked->forceFill(['status' => 'approved', 'approved_by' => $approver->id, 'decided_at' => now(), 'decision_note' => $note])->save();
                $this->audit->log('permission_change_request.approved', $locked, ['status' => 'pending'], ['status' => 'approved'], $approver->id, $locked->id);

                return $locked;
            });
        }

        $permissions = $diff['action'] === 'role_grant.create'
            ? $this->templatePermissions(PermissionTemplate::where('code', $diff['template_code'])->whereNull('retired_at')->firstOrFail())
            : [$diff['permission'] => $diff['scope']];
        $units = $diff['action'] === 'role_grant.create' ? $diff['org_unit_ids'] : array_filter([$diff['org_unit_id']]);
        $this->assertCeiling($requester, $target, $permissions, $units, 'ผู้ยื่นคำขอไม่มีสิทธิ์ครอบคลุมสิ่งที่ขอแล้ว — ปฏิเสธคำขอนี้');

        return DB::transaction(function () use ($approver, $request, $target, $diff, $note) {
            $locked = PermissionChangeRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);

            $diff['action'] === 'role_grant.create'
                ? $this->applyRoleGrant($locked->requested_by, $approver->id, $target, $diff, $locked->id)
                : $this->applyOverride($locked->requested_by, $approver->id, $target, $diff, $locked->id);

            $locked->forceFill(['status' => 'approved', 'approved_by' => $approver->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->audit->log('permission_change_request.approved', $locked, ['status' => 'pending'], ['status' => 'approved'], $approver->id, $locked->id);

            return $locked;
        });
    }

    public function reject(User $approver, PermissionChangeRequest $request, string $note): PermissionChangeRequest
    {
        $this->assertPending($request);
        $request->forceFill(['status' => 'rejected', 'approved_by' => $approver->id, 'decided_at' => now(), 'decision_note' => $note])->save();
        $this->audit->log('permission_change_request.rejected', $request, ['status' => 'pending'], ['status' => 'rejected', 'note' => $note], $approver->id, $request->id);

        return $request;
    }

    /** ผู้ยื่นถอนคำขอของตนเอง */
    public function cancel(User $actor, PermissionChangeRequest $request): PermissionChangeRequest
    {
        if ($request->requested_by !== $actor->id) {
            throw new HttpException(403, 'ถอนได้เฉพาะคำขอที่ตนยื่น');
        }
        $this->assertPending($request);
        $request->forceFill(['status' => 'cancelled', 'decided_at' => now()])->save();
        $this->audit->log('permission_change_request.cancelled', $request, ['status' => 'pending'], ['status' => 'cancelled'], $actor->id, $request->id);

        return $request;
    }

    // ------------------------------------------------------------------ ภายใน

    /** @param  array<string, mixed>  $diff */
    private function applyRoleGrant(int $grantedBy, ?int $approvedBy, User $target, array $diff, ?int $requestId): RoleGrant
    {
        $grant = RoleGrant::create([
            'user_id' => $target->id,
            'template_id' => PermissionTemplate::where('code', $diff['template_code'])->value('id'),
            'attributes' => $diff['org_unit_ids'] !== [] ? ['org_unit_ids' => $diff['org_unit_ids']] : null,
            'order_number' => $diff['order_number'],
            'granted_by' => $grantedBy,
            'approved_by' => $approvedBy,
            'effective_from' => $diff['effective_from'],
            'expires_at' => $diff['expires_at'],
        ]);
        $this->audit->log('role_grant.created', $grant, null, $diff + ['user_id' => $target->id], $approvedBy ?? $grantedBy, $requestId);

        return $grant;
    }

    /** @param  array<string, mixed>  $diff */
    private function applyOverride(int $grantedBy, ?int $approvedBy, User $target, array $diff, ?int $requestId): PermissionOverride
    {
        $override = PermissionOverride::create([
            'user_id' => $target->id,
            'permission_id' => Permission::where('name', $diff['permission'])->value('id'),
            'effect' => $diff['effect'],
            'scope' => $diff['scope'],
            'org_unit_id' => $diff['org_unit_id'],
            'reason' => $diff['reason'],
            'granted_by' => $grantedBy,
            'approved_by' => $approvedBy,
            'expires_at' => $diff['expires_at'],
        ]);
        $this->audit->log('permission_override.created', $override, null, $diff + ['user_id' => $target->id], $approvedBy ?? $grantedBy, $requestId);

        return $override;
    }

    /** @param  array<string, mixed>  $diff */
    private function openRequest(User $actor, User $target, array $diff, ?string $reason): PermissionChangeRequest
    {
        $request = PermissionChangeRequest::create([
            'target' => ['type' => 'user', 'user_id' => $target->id, 'user_uuid' => $target->uuid],
            'diff' => $diff,
            'affected_user_count' => 1,
            'reason' => $reason,
            'requested_by' => $actor->id,
            'status' => 'pending',
        ]);
        $this->audit->log('permission_change_request.created', $request, null, $diff, $actor->id, $request->id);

        return $request;
    }

    private function guardTarget(User $actor, ?User $target): void
    {
        if ($target === null) {
            throw ValidationException::withMessages(['user' => 'ไม่พบบัญชี']);
        }
        if ($actor->is($target)) {
            throw new HttpException(403, 'มอบหรือถอนสิทธิ์ของตนเองไม่ได้ (not_own)');
        }
        if ($target->status === User::STATUS_SEPARATED) {
            throw ValidationException::withMessages(['user' => 'บัญชีนี้ปิดแล้ว']);
        }
    }

    /**
     * grant_ceiling — ทุก permission ที่จะให้ ผู้มอบต้องถือใน scope ≥ และครอบคลุมหน่วยที่ให้
     * หน่วยที่ให้ = หน่วยที่ระบุ หรือหน่วยหลักของผู้รับ (ตรงกับที่ engine ใช้ยึด scope ของ grant)
     *
     * @param  array<string, string>  $permissions  name => scope
     * @param  array<int, int>  $unitIds
     */
    private function assertCeiling(User $granter, User $target, array $permissions, array $unitIds, ?string $message = null): void
    {
        $units = $unitIds !== []
            ? OrgUnit::whereIn('id', $unitIds)->get()
            : OrgUnit::whereIn('id', Assignment::where('personnel_id', $target->personnel_id ?? 0)
                ->where('kind', Assignment::KIND_PRIMARY)->effectiveOn(today()->toDateString())->pluck('org_unit_id'))->get();

        $exceeding = [];
        foreach ($permissions as $name => $scope) {
            $ok = $units->isEmpty()
                ? $this->engine->canGrant($granter, $name, $scope)
                : $units->every(fn (OrgUnit $unit) => $this->engine->canGrant($granter, $name, $scope, $unit));
            if (! $ok) {
                $exceeding[] = "{$name} ({$scope})";
            }
        }

        if ($exceeding !== []) {
            throw new HttpException(403, ($message ?? 'ให้สิทธิ์เกินที่ตนมีไม่ได้ (grant_ceiling)').': '.implode(', ', $exceeding));
        }
    }

    /** @return array<string, string> permission name => scope (เฉพาะ allow) */
    private function templatePermissions(PermissionTemplate $template): array
    {
        return TemplatePermission::with('permission:id,name')
            ->where('template_id', $template->id)
            ->where('effect', '!=', 'deny')
            ->get()
            ->mapWithKeys(fn (TemplatePermission $row) => [$row->permission->name => $row->scope])
            ->all();
    }

    private function assertPending(Model $request): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['request' => 'คำขอนี้พิจารณาไปแล้ว']);
        }
    }
}
