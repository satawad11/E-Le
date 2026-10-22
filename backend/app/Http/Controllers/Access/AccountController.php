<?php

namespace App\Http\Controllers\Access;

use App\Authz\PermissionEngine;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Authz\AccessReview;
use App\Models\Authz\PermissionChangeRequest;
use App\Models\Authz\PermissionOverride;
use App\Models\Authz\RoleGrant;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Access\AccessGrantService;
use App\Services\Access\AccessReviewService;
use App\Services\Access\AccountLifecycleService;
use App\Services\Access\MfaPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * บัญชีผู้ใช้ (ต้นแบบหน้า 7 · PLAN §8.2 ช.1) — `access.account.view` / `access.account.manage`
 *
 * รายการกรองด้วย PermissionEngine::scopeQuery (ไม่กรองที่หน้าเว็บอย่างเดียว) · จำนวนตามสถานะนับจากชุดเดียวกัน
 * ปุ่มจัดการต่อแถวมาจาก engine (`abilities`) — การตรวจจริงอยู่ที่ endpoint จัดการทุกตัว
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly PermissionEngine $engine,
        private readonly MfaPolicy $mfa,
        private readonly AccountLifecycleService $lifecycle,
    ) {}

    public function index(Request $request, AccessReviewService $reviews): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(User::STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $actor = $request->user();

        $scoped = fn (): Builder => $this->engine->scopeQuery($actor, 'access.account.view', User::query());

        $query = $scoped()->with(['personnel'])
            ->when($data['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($data['q'] ?? null, function (Builder $q, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn (Builder $w) => $w->where('username', 'like', $like)->orWhere('email', 'like', $like)
                    ->orWhereHas('personnel', fn (Builder $p) => $p->where('personnel_code', 'like', $like)
                        ->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like)));
            })
            ->orderByRaw("case status when 'locked' then 0 when 'pending' then 1 when 'active' then 2 when 'suspended' then 3 else 4 end")
            ->orderBy('username');

        $page = $query->paginate($data['per_page'] ?? 25);
        $counts = $scoped()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $openReview = AccessReview::where('status', 'open')->latest('id')->first();

        return response()->json([
            'data' => collect($page->items())->map(fn (User $u) => $this->presentRow($actor, $u))->values(),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'counts' => collect(User::STATUSES)->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])
                    ->put('all', (int) $counts->sum()),
                'lifecycle_this_month' => $this->lifecycleThisMonth(),
                'review' => $openReview ? [
                    'uuid' => $openReview->uuid,
                    'name' => $openReview->name,
                    'due_at' => $openReview->due_at?->toDateString(),
                ] + $reviews->progress($openReview) : null,
                'policy' => [
                    'mfa_levels' => config('auth.mfa.levels'),
                    'lockout_attempts' => (int) config('auth.lockout.attempts'),
                    'lockout_minutes' => (int) config('auth.lockout.minutes'),
                    'session_hours' => round(((int) config('sanctum.expiration', 480)) / 60, 1),
                    'inactive_days' => (int) config('auth.inactive_days'),
                ],
                'can' => [
                    'manage' => $this->engine->allows($actor, 'access.account.manage'),
                    'grant' => $this->engine->allows($actor, 'access.grant.manage'),
                    'review' => $this->engine->allows($actor, 'access.review.confirm') || $this->engine->allows($actor, 'access.review.manage'),
                ],
            ],
        ]);
    }

    /** ที่มาของสิทธิ์ของบัญชีหนึ่ง (ตำแหน่ง ชุดสิทธิ์ สิทธิ์รายบุคคล คำขอค้าง เข้าใช้ล่าสุด) */
    public function access(Request $request, User $user): JsonResponse
    {
        $this->authorizeResource('access.account.view', $user);
        $actor = $request->user();

        $assignments = $user->personnel_id === null ? collect() : Assignment::with(['orgUnit:id,uuid,name', 'position:id,uuid,name'])
            ->where('personnel_id', $user->personnel_id)->effectiveOn(today()->toDateString())->get();

        return response()->json([
            'account' => $this->presentRow($actor, $user->load('personnel')),
            'assignments' => $assignments->map(fn (Assignment $a) => [
                'uuid' => $a->uuid, 'kind' => $a->kind,
                'position' => $a->position?->name, 'org_unit' => $a->orgUnit?->name,
                'effective_from' => $a->effective_from?->toDateString(), 'effective_to' => $a->effective_to?->toDateString(),
            ])->values(),
            'role_grants' => RoleGrant::with('template:id,code,name')->where('user_id', $user->id)->orderByDesc('id')->get()->map(fn (RoleGrant $g) => [
                'uuid' => $g->uuid, 'template' => $g->template?->only(['code', 'name']), 'order_number' => $g->order_number,
                'effective_from' => $g->effective_from?->toDateString(), 'expires_at' => $g->expires_at?->toDateString(),
                'ended_at' => $g->ended_at?->toIso8601String(), 'end_reason' => $g->end_reason,
                'active' => $g->ended_at === null && $g->expires_at?->gte(today()),
            ])->values(),
            'overrides' => PermissionOverride::with(['permission:id,name,label', 'orgUnit:id,uuid,name'])->where('user_id', $user->id)->orderByDesc('id')->get()
                ->map(fn (PermissionOverride $o) => [
                    'uuid' => $o->uuid, 'permission' => $o->permission?->name, 'label' => $o->permission?->label,
                    'effect' => $o->effect, 'scope' => $o->scope, 'org_unit' => $o->orgUnit?->name, 'reason' => $o->reason,
                    'expires_at' => $o->expires_at?->toDateString(), 'ended_at' => $o->ended_at?->toIso8601String(),
                ])->values(),
            'pending_requests' => PermissionChangeRequest::where('status', 'pending')->where('target->user_id', $user->id)->get()
                ->map(fn (PermissionChangeRequest $r) => ['uuid' => $r->uuid, 'diff' => $r->diff, 'created_at' => $r->created_at?->toIso8601String()])->values(),
            'recent_logins' => LoginEvent::where('user_id', $user->id)->orderByDesc('id')->limit(10)->get()
                ->map(fn (LoginEvent $e) => ['result' => $e->result, 'ip' => $e->ip, 'at' => $e->created_at?->toIso8601String()])->values(),
        ]);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $note = $this->authorizeManage($request, $user);

        return response()->json($this->presentRow($request->user(), $this->lifecycle->suspend($user, $request->user(), $note)));
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $note = $this->authorizeManage($request, $user);

        return response()->json($this->presentRow($request->user(), $this->lifecycle->reactivate($user, $request->user(), $note)));
    }

    public function unlock(Request $request, User $user): JsonResponse
    {
        $note = $this->authorizeManage($request, $user);

        return response()->json($this->presentRow($request->user(), $this->lifecycle->unlock($user, $request->user(), $note)));
    }

    /**
     * รีเซ็ต MFA — บัญชีที่ MfaPolicy บังคับ (L1–L3, งานบุคคล, ผู้ดูแลระบบ, break-glass) ต้องผ่านผู้ยืนยันคนที่สอง (202 + คำขอ)
     * บัญชีอื่นมีผลทันที · ทั้งสองทางแจ้งเจ้าของบัญชีและบันทึก login_events/audit
     */
    public function resetMfa(Request $request, User $user, AccessGrantService $grants): JsonResponse
    {
        $note = $this->authorizeManage($request, $user);

        if ($this->mfa->requires($user)) {
            $changeRequest = $grants->requestMfaReset($request->user(), $user, $note);

            return response()->json([
                'message' => 'บัญชีนี้ถูกบังคับใช้ MFA — ส่งคำขอให้ผู้ดูแลคนที่สองยืนยันแล้ว ยังไม่มีผลจนกว่าจะได้รับการยืนยัน',
                'change_request' => ['uuid' => $changeRequest->uuid, 'status' => $changeRequest->status],
            ], 202);
        }

        return response()->json($this->presentRow($request->user(), $this->lifecycle->resetMfa($user, $request->user(), $note)));
    }

    // ------------------------------------------------------------------ ภายใน

    private function authorizeManage(Request $request, User $user): string
    {
        $this->authorizeResource('access.account.manage', $user);
        abort_if($request->user()->is($user), 403, 'จัดการบัญชีของตนเองไม่ได้ (not_own)');

        return $request->validate(['note' => ['required', 'string', 'max:500']])['note'];
    }

    /** @return array<string, mixed> */
    private function presentRow(User $actor, User $user): array
    {
        $personnel = $user->personnel;
        $today = today()->toDateString();
        $reasons = $user->status === User::STATUS_SEPARATED ? [] : $this->mfa->reasons($user);

        $assignments = $personnel === null ? collect() : Assignment::where('personnel_id', $personnel->id)->effectiveOn($today)->pluck('kind');

        return [
            'uuid' => $user->uuid,
            'username' => $user->username,
            'email' => $user->email,
            'name' => $personnel?->full_name ?? $user->username,
            'account_type' => $user->account_type,
            'is_break_glass' => $user->is_break_glass,
            'personnel' => $personnel ? [
                'uuid' => $personnel->uuid,
                'personnel_code' => $personnel->personnel_code,
                'status' => $personnel->status,
                'started_at' => $personnel->started_at?->toDateString(),
            ] : null,
            'status' => $user->status,
            'status_reason' => $user->status_reason,
            'status_changed_at' => $user->status_changed_at?->toIso8601String(),
            'locked_until' => $user->locked_until?->toIso8601String(),
            'separated_at' => $user->separated_at?->toDateString(),
            'separation_reason' => $user->separation_reason,
            'mfa' => [
                'enabled' => $user->mfaCredentials()->whereNotNull('confirmed_at')->exists(),
                'required' => $reasons !== [],
                'reasons' => $reasons,
            ],
            'sources' => [
                'positions' => $assignments->filter(fn ($k) => $k !== Assignment::KIND_ACTING)->count(),
                'acting' => $assignments->filter(fn ($k) => $k === Assignment::KIND_ACTING)->count(),
                'grants' => RoleGrant::where('user_id', $user->id)->whereNull('ended_at')->whereDate('expires_at', '>=', $today)->count(),
                'overrides' => PermissionOverride::where('user_id', $user->id)->whereNull('ended_at')->where('effect', 'allow')
                    ->whereDate('expires_at', '>=', $today)->count(),
            ],
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'abilities' => [
                'manage' => ! $actor->is($user) && $this->engine->allows($actor, 'access.account.manage', $user),
                'grant' => ! $actor->is($user) && $this->engine->allows($actor, 'access.grant.manage', $user),
            ],
        ];
    }

    /** @return array{joiner: int, mover: int, leaver: int} */
    private function lifecycleThisMonth(): array
    {
        $since = now()->startOfMonth();
        $count = fn (Collection|array $actions) => AuditLog::whereIn('action', (array) $actions)->where('created_at', '>=', $since);

        return [
            'joiner' => $count(['account.created'])->count(),
            'mover' => $count(['assignment.created', 'assignment.ended'])->count(),
            'leaver' => $count(['account.status_changed'])->where('after->status', User::STATUS_SEPARATED)->count(),
        ];
    }
}
