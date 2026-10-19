<?php

namespace App\Services\Access;

use App\Models\Assignment;
use App\Models\Authz\PermissionChangeRequest;
use App\Models\Authz\PermissionOverride;
use App\Models\Authz\RoleGrant;
use App\Models\Personnel;
use App\Models\PersonnelChangeRequest;
use App\Models\User;
use App\Notifications\AccountNotice;
use App\Services\AuditLogger;
use App\Services\Personnel\AssignmentService;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * วงจรบัญชี Joiner–Mover–Leaver (CORE-03 · R05-1)
 *
 * | สถานะบุคลากร              | บัญชี                                                            |
 * |---------------------------|------------------------------------------------------------------|
 * | pending                   | สร้างบัญชี `pending` (ยังเข้าใช้ไม่ได้)                           |
 * | active / seconded         | สร้าง/เปิดบัญชี `active` + ส่งลิงก์ตั้งรหัสผ่าน                     |
 * | study_leave / suspended   | `suspended` (token ถูกตัดโดย UserObserver)                       |
 * | separated                 | `separated` · ตัด token · ปิด assignment/grant/override · ยกเลิกคำขอค้าง · แจ้งงานบุคคลเรื่องงานค้าง |
 *
 * ทุกข้อ idempotent — event (PersonnelStatusChanged) และ job รายวัน `accounts:reconcile` เรียกซ้ำได้
 * `users.status_reason` บอกว่าใครตั้งสถานะ: JML คืนสถานะเฉพาะที่ตั้งจากสถานะบุคลากร (`personnel:*`)
 * ไม่ปลดการระงับของผู้ดูแล (`manual`) หรือของการไม่เข้าใช้ (`inactive_90_days`)
 */
class AccountLifecycleService
{
    public const REASON_PERSONNEL = 'personnel:';

    public const REASON_INACTIVE = 'inactive_90_days';

    public const REASON_MANUAL = 'manual';

    public const REASON_FAILED_LOGINS = 'failed_logins';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionAudit $permissionAudit,
        private readonly AssignmentService $assignments,
        private readonly AccessRecipients $recipients,
    ) {}

    // ------------------------------------------------------------------ JML

    /**
     * ปรับบัญชีให้ตรงกับสถานะบุคลากร — คืนสิ่งที่ทำ (joined, activated, reactivated, suspended, separated, missing_email) หรือ null
     */
    public function syncFromPersonnel(Personnel $personnel, ?int $actorId = null): ?string
    {
        $user = User::where('personnel_id', $personnel->id)->first();

        return match ($personnel->status) {
            'pending' => $this->joiner($personnel, $user, User::STATUS_PENDING, $actorId),
            'active', 'seconded' => $this->joiner($personnel, $user, User::STATUS_ACTIVE, $actorId),
            'study_leave', 'suspended' => $user ? $this->suspendForPersonnel($user, $personnel->status, $actorId) : null,
            'separated' => $user ? $this->leaver($user, $personnel, $actorId) : null,
            default => null,
        };
    }

    private function joiner(Personnel $personnel, ?User $user, string $target, ?int $actorId): ?string
    {
        $reason = self::REASON_PERSONNEL.$personnel->status;

        if ($user === null) {
            $email = $personnel->profile?->email_work;
            if (blank($email) || User::where('email', $email)->exists()) {
                return 'missing_email';
            }

            $username = User::where('username', $personnel->personnel_code)->exists()
                ? $personnel->personnel_code.'-'.$personnel->id
                : $personnel->personnel_code;

            $user = new User;
            $user->forceFill([
                'personnel_id' => $personnel->id,
                'username' => $username,
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
                'status' => $target,
                'status_reason' => $reason,
                'status_changed_at' => now(),
                'account_type' => User::TYPE_PERSONNEL,
            ])->save();

            $this->audit->log('account.created', $user, null, null, ['status' => $target, 'username' => $username], 'JML: '.$reason, $actorId);
            if ($target === User::STATUS_ACTIVE) {
                $this->sendSetupLink($user);
            }

            return 'joined';
        }

        $from = $user->status;
        $byPersonnel = $user->status_reason === null || str_starts_with($user->status_reason, self::REASON_PERSONNEL);

        $reopen = match (true) {
            $from === User::STATUS_SEPARATED => true,                            // กลับเข้ารับราชการ/ต่อสัญญา
            $from === User::STATUS_PENDING => $target === User::STATUS_ACTIVE,  // ถึงวันเริ่มงาน
            $from === User::STATUS_SUSPENDED => $byPersonnel,                    // กลับจากลาศึกษา/พักราชการ
            default => false,
        };

        if (! $reopen) {
            return null;
        }

        if ($from === User::STATUS_SEPARATED) {
            // รหัสผ่านเดิมของคนที่พ้นสภาพไปแล้วต้องใช้ไม่ได้ — ตั้งใหม่ผ่านลิงก์
            $user->forceFill(['password' => Hash::make(Str::random(64)), 'separated_at' => null, 'separation_reason' => null]);
        }
        $this->setStatus($user, $target, $reason, $actorId, 'JML');
        if ($target === User::STATUS_ACTIVE && $from !== User::STATUS_SUSPENDED) {
            $this->sendSetupLink($user);
        }

        return $from === User::STATUS_PENDING ? 'activated' : 'reactivated';
    }

    private function suspendForPersonnel(User $user, string $personnelStatus, ?int $actorId): ?string
    {
        if (in_array($user->status, [User::STATUS_SEPARATED, User::STATUS_SUSPENDED], true)) {
            return null;
        }

        $this->setStatus($user, User::STATUS_SUSPENDED, self::REASON_PERSONNEL.$personnelStatus, $actorId, 'JML');

        return 'suspended';
    }

    /**
     * Leaver — ทำซ้ำได้: ทุกครั้งปิดสิ่งที่ยังมีผลที่เหลืออยู่ แต่แจ้งงานบุคคลเฉพาะครั้งที่เปลี่ยนสถานะ
     */
    private function leaver(User $user, Personnel $personnel, ?int $actorId): ?string
    {
        $date = ($personnel->separated_at ?? today())->toDateString();
        $note = 'พ้นสภาพ'.($personnel->separation_reason ? ': '.$personnel->separation_reason : '');
        $transition = $user->status !== User::STATUS_SEPARATED;

        $summary = DB::transaction(function () use ($user, $personnel, $date, $note, $actorId, $transition) {
            if ($transition) {
                $user->forceFill(['separated_at' => $date, 'separation_reason' => mb_substr((string) $personnel->separation_reason, 0, 255) ?: null]);
                $this->setStatus($user, User::STATUS_SEPARATED, self::REASON_PERSONNEL.'separated', $actorId, 'JML');
            }
            $tokens = $user->tokens()->delete();

            $assignments = 0;
            $open = Assignment::where('personnel_id', $personnel->id)
                ->whereNull('end_reason')
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>', $date))
                ->get();
            foreach ($open as $assignment) {
                $end = $assignment->effective_from->toDateString() > $date ? $assignment->effective_from->toDateString() : $date;
                $this->assignments->end($assignment, $end, $note, $actorId);
                $assignments++;
            }

            $grants = 0;
            foreach (RoleGrant::where('user_id', $user->id)->whereNull('ended_at')->get() as $grant) {
                $grant->forceFill(['ended_at' => now(), 'ended_by' => $actorId, 'end_reason' => $note])->save();
                $this->permissionAudit->log('role_grant.ended', $grant, ['ended_at' => null], ['ended_at' => now()->toIso8601String(), 'end_reason' => $note], $actorId);
                $grants++;
            }

            $overrides = 0;
            foreach (PermissionOverride::where('user_id', $user->id)->whereNull('ended_at')->get() as $override) {
                $override->forceFill(['ended_at' => now()])->save();
                $this->permissionAudit->log('permission_override.ended', $override, ['ended_at' => null], ['ended_at' => now()->toIso8601String(), 'reason' => $note], $actorId);
                $overrides++;
            }

            $cancelled = 0;
            $pending = PermissionChangeRequest::where('status', 'pending')
                ->where(fn ($q) => $q->where('requested_by', $user->id)->orWhere('target->user_id', $user->id))
                ->get();
            foreach ($pending as $request) {
                $request->forceFill(['status' => 'cancelled', 'decided_at' => now(), 'decision_note' => $note])->save();
                $this->permissionAudit->log('permission_change_request.cancelled', $request, ['status' => 'pending'], ['status' => 'cancelled'], $actorId, $request->id);
                $cancelled++;
            }

            return [
                'tokens_revoked' => $tokens,
                'assignments_ended' => $assignments,
                'grants_ended' => $grants,
                'overrides_ended' => $overrides,
                'change_requests_cancelled' => $cancelled,
                'pending_personnel_requests' => PersonnelChangeRequest::where('requested_by', $user->id)->where('status', 'pending')->count(),
                'pending_leaves' => Schema::hasTable('leaves')
                    ? DB::table('leaves')->where('user_id', $user->id)->where('status', 'Pending')->whereNull('deleted_at')->count()
                    : 0,
            ];
        });

        $changed = $transition || array_sum(array_slice($summary, 0, 5)) > 0;
        if ($changed) {
            $this->audit->log('account.leaver_cleanup', $user, null, null, $summary, $note, $actorId);
        }

        if ($transition) {
            Notification::send($this->recipients->hr(), new AccountNotice(
                AccountNotice::LEAVER_HANDOVER,
                'บัญชีผู้พ้นสภาพถูกปิด: '.$personnel->full_name,
                [
                    "ระบบปิดบัญชีของ {$personnel->full_name} ({$personnel->personnel_code}) ตามสถานะพ้นสภาพ วันที่ {$date}",
                    "ปิดการดำรงตำแหน่ง {$summary['assignments_ended']} รายการ · ถอนชุดสิทธิ์ {$summary['grants_ended']} · ถอนสิทธิ์รายบุคคล {$summary['overrides_ended']}",
                    "งานค้างที่ต้องมอบหมายต่อ: คำขอแก้ไขข้อมูล {$summary['pending_personnel_requests']} รายการ · ใบลารอดำเนินการ {$summary['pending_leaves']} รายการ",
                ],
            ));
        }

        return $transition ? 'separated' : null;
    }

    // ------------------------------------------------------------------ job รายวัน

    /**
     * `accounts:reconcile` — ทำซ้ำทุกข้อของ JML และนโยบาย ให้ผลเหมือนเดิมเมื่อรันซ้ำ
     *
     * @return array<string, int>
     */
    public function reconcile(): array
    {
        $tally = [];

        Personnel::with('profile')->orderBy('id')->chunkById(200, function ($people) use (&$tally) {
            foreach ($people as $personnel) {
                $result = $this->syncFromPersonnel($personnel);
                if ($result !== null) {
                    $tally[$result] = ($tally[$result] ?? 0) + 1;
                }
            }
        });

        $tally['unlocked'] = $this->releaseExpiredLocks();
        $tally['inactive_suspended'] = $this->suspendInactive();
        $tally['grants_expired'] = $this->endExpiredGrants();

        return $tally;
    }

    public function releaseExpiredLocks(): int
    {
        $count = 0;
        foreach (User::where('status', User::STATUS_LOCKED)->where('locked_until', '<=', now())->get() as $user) {
            $this->setStatus($user, User::STATUS_ACTIVE, null, null, 'ครบเวลาล็อก');
            $count++;
        }

        return $count;
    }

    /** บัญชีบุคลากรที่ไม่เข้าใช้ตาม `auth.inactive_days` → ระงับและแจ้งงานบุคคล (ไม่รวม break-glass และบัญชีระบบ) */
    public function suspendInactive(): int
    {
        $cutoff = now()->subDays((int) config('auth.inactive_days', 90));
        $users = User::where('status', User::STATUS_ACTIVE)
            ->where('account_type', User::TYPE_PERSONNEL)
            ->where('is_break_glass', false)
            ->where(fn ($q) => $q->where('last_login_at', '<', $cutoff)
                ->orWhere(fn ($w) => $w->whereNull('last_login_at')->where(fn ($x) => $x->where('status_changed_at', '<', $cutoff)
                    ->orWhere(fn ($y) => $y->whereNull('status_changed_at')->where('created_at', '<', $cutoff)))))
            ->with('personnel')
            ->get();

        foreach ($users as $user) {
            $this->setStatus($user, User::STATUS_SUSPENDED, self::REASON_INACTIVE, null, 'ไม่เข้าใช้ '.config('auth.inactive_days', 90).' วัน');
        }

        if ($users->isNotEmpty()) {
            Notification::send($this->recipients->hr(), new AccountNotice(
                AccountNotice::INACTIVE_SUSPENDED,
                'ระงับบัญชีที่ไม่เข้าใช้ '.$users->count().' บัญชี',
                array_merge(
                    ['บัญชีต่อไปนี้ไม่เข้าใช้เกิน '.config('auth.inactive_days', 90).' วัน ระบบระงับไว้ เปิดใหม่ได้ที่หน้าบัญชีผู้ใช้เมื่อเจ้าของยืนยันตัวตน'],
                    $users->map(fn (User $u) => '• '.($u->personnel?->full_name ?? $u->username))->all(),
                ),
            ));
        }

        return $users->count();
    }

    /** ปิดแถว role grant / override allow ที่หมดอายุแล้ว (engine ไม่นับอยู่แล้ว — ปิดเพื่อให้ทะเบียนตรงกับความจริง) */
    public function endExpiredGrants(): int
    {
        $today = today()->toDateString();
        $count = 0;

        foreach (RoleGrant::whereNull('ended_at')->whereDate('expires_at', '<', $today)->get() as $grant) {
            $grant->forceFill(['ended_at' => now(), 'end_reason' => 'หมดอายุตามคำสั่ง'])->save();
            $this->permissionAudit->log('role_grant.expired', $grant, ['ended_at' => null], ['ended_at' => now()->toIso8601String()], null);
            $count++;
        }
        foreach (PermissionOverride::whereNull('ended_at')->whereNotNull('expires_at')->whereDate('expires_at', '<', $today)->get() as $override) {
            $override->forceFill(['ended_at' => now()])->save();
            $this->permissionAudit->log('permission_override.expired', $override, ['ended_at' => null], ['ended_at' => now()->toIso8601String()], null);
            $count++;
        }

        return $count;
    }

    // ------------------------------------------------------------------ การจัดการโดยผู้ดูแล (access.account.manage)

    public function suspend(User $user, User $actor, string $note): User
    {
        if ($user->status === User::STATUS_SEPARATED) {
            throw ValidationException::withMessages(['status' => 'บัญชีนี้ปิดแล้ว']);
        }
        $this->setStatus($user, User::STATUS_SUSPENDED, self::REASON_MANUAL, $actor->id, $note);

        return $user;
    }

    public function reactivate(User $user, User $actor, string $note): User
    {
        if (! in_array($user->status, [User::STATUS_SUSPENDED, User::STATUS_LOCKED], true)) {
            throw ValidationException::withMessages(['status' => 'เปิดใช้ได้เฉพาะบัญชีที่ถูกระงับหรือล็อก']);
        }
        if ($user->personnel_id !== null && ! in_array($user->personnel?->status, ['active', 'seconded'], true)) {
            throw ValidationException::withMessages(['status' => 'บุคลากรไม่อยู่ในสถานะปฏิบัติงาน — บัญชีเปิดตามสถานะบุคลากรอัตโนมัติ']);
        }
        $this->setStatus($user, User::STATUS_ACTIVE, self::REASON_MANUAL, $actor->id, $note);

        return $user;
    }

    public function unlock(User $user, User $actor, string $note): User
    {
        if ($user->status !== User::STATUS_LOCKED) {
            throw ValidationException::withMessages(['status' => 'บัญชีนี้ไม่ได้ถูกล็อก']);
        }
        $this->setStatus($user, User::STATUS_ACTIVE, null, $actor->id, $note);

        return $user;
    }

    /** ล้าง MFA (เช่น โทรศัพท์หาย) — ผู้ใช้ต้องตั้งใหม่ตอนเข้าสู่ระบบครั้งถัดไป · ตัด token ทั้งหมด */
    public function resetMfa(User $user, User $actor, string $note): User
    {
        $removed = $user->mfaCredentials()->delete();
        $user->tokens()->delete();
        $this->audit->log('account.mfa_reset', $user, null, ['credentials' => $removed], null, $note, $actor->id);

        return $user;
    }

    // ------------------------------------------------------------------ ภายใน

    public function setStatus(User $user, string $status, ?string $reason, ?int $actorId, ?string $note = null): void
    {
        $before = ['status' => $user->getOriginal('status'), 'status_reason' => $user->getOriginal('status_reason')];

        $user->forceFill([
            'status' => $status,
            'status_reason' => $reason,
            'status_changed_at' => now(),
        ]);
        if ($status === User::STATUS_ACTIVE) {
            $user->forceFill(['locked_until' => null, 'failed_login_attempts' => 0]);
        }
        $user->save();

        if ($before['status'] !== $status || $before['status_reason'] !== $reason) {
            $this->audit->log('account.status_changed', $user, null, $before, ['status' => $status, 'status_reason' => $reason], $note, $actorId);
        }
    }

    /** ลิงก์ตั้งรหัสผ่าน (ใช้ broker เดียวกับลืมรหัสผ่าน — หมดอายุตาม auth.passwords) */
    public function sendSetupLink(User $user): void
    {
        /** @var PasswordBroker $broker */
        $broker = Password::broker();
        $token = $broker->createToken($user);
        $url = rtrim((string) config('app.frontend_url'), '/').'/reset-password?token='.urlencode($token).'&email='.urlencode($user->email);

        $user->notify(new AccountNotice(
            AccountNotice::ACCOUNT_SETUP,
            'เปิดบัญชีระบบบุคลากรของคุณ',
            ['บัญชีของคุณในระบบบุคลากรเปิดใช้งานแล้ว ชื่อผู้ใช้: '.$user->username, 'กรุณาตั้งรหัสผ่านภายในเวลาที่ลิงก์กำหนด'],
            'ตั้งรหัสผ่าน',
            $url,
        ));
    }
}
