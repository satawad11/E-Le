<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * ทะเบียนการมอบบทบาทพร้อมเลขคำสั่งและวันหมดอายุ (ACCESS-GOV-01 · C3)
 *
 * ผู้ใช้หนึ่งคนมีบทบาทเดียว (ฟอร์มผู้ใช้ใช้ syncRoles) — การมอบใหม่จึงปิดแถวที่เปิดอยู่แล้วเปิดแถวใหม่
 * บทบาทพื้นฐาน (config authorization.base_role) คือบทบาทที่ผู้ใช้กลับไปเมื่อบทบาทหมดอายุ
 */
class RoleGrantService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * บันทึกการมอบบทบาทหลัง syncRoles — ไม่ทำอะไรถ้าบทบาท เลขคำสั่ง และวันหมดอายุเท่าเดิม
     */
    public function record(User $user, Role $role, ?User $actor, ?string $orderNumber = null, ?string $expiresAt = null, string $endReason = 'เปลี่ยนบทบาท'): void
    {
        $orderNumber = filled($orderNumber) ? trim($orderNumber) : null;
        $expiresAt = filled($expiresAt) ? $expiresAt : null;

        $open = DB::table('user_role_grants')->where('user_id', $user->id)->whereNull('ended_at')->orderByDesc('id')->first();
        if ($open !== null && (int) $open->role_id === $role->id
            && $open->order_number === $orderNumber && $this->date($open->expires_at) === $expiresAt) {
            return;
        }

        DB::transaction(function () use ($user, $role, $actor, $orderNumber, $expiresAt, $endReason) {
            DB::table('user_role_grants')->where('user_id', $user->id)->whereNull('ended_at')->update([
                'ended_at' => now(), 'ended_by' => $actor?->id, 'end_reason' => $endReason, 'updated_at' => now(),
            ]);

            $id = DB::table('user_role_grants')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $role->id,
                'role_name' => $role->name,
                'order_number' => $orderNumber,
                'effective_from' => now()->toDateString(),
                'expires_at' => $expiresAt,
                'granted_by' => $actor?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->log('access.role_granted', 'user_role_grant', $id, null, [
                'user_id' => $user->id, 'role' => $role->name, 'order_number' => $orderNumber, 'expires_at' => $expiresAt,
            ], $orderNumber ? 'คำสั่งที่ '.$orderNumber : null, $actor?->id);
        });
    }

    /**
     * ถอนบทบาทที่หมดอายุ (วันหมดอายุ < วันนี้) กลับเป็นบทบาทพื้นฐาน
     *
     * @return int จำนวนผู้ใช้ที่ถูกถอน
     */
    public function expireDue(?string $today = null): int
    {
        $today ??= now()->toDateString();
        $base = Role::where('name', (string) config('authorization.base_role', 'Employee'))->first();

        $due = DB::table('user_role_grants')->whereNull('ended_at')->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', $today)->get();

        foreach ($due as $grant) {
            $user = User::find($grant->user_id);
            if ($user === null) {
                continue;
            }

            DB::transaction(function () use ($user, $grant, $base) {
                $before = $user->roles->pluck('name')->all();
                // ถอนเฉพาะเมื่อผู้ใช้ยังถือบทบาทที่หมดอายุอยู่ — ถ้าถูกเปลี่ยนไปแล้วทางอื่น แค่ปิดแถว
                if ($grant->role_id !== null && $user->roles->contains('id', (int) $grant->role_id)) {
                    $user->syncRoles($base ? [$base->name] : []);
                }

                DB::table('user_role_grants')->where('id', $grant->id)->update([
                    'ended_at' => now(), 'end_reason' => 'หมดอายุ', 'updated_at' => now(),
                ]);

                if ($base !== null && $user->fresh()->roles->contains('id', $base->id)) {
                    DB::table('user_role_grants')->insert([
                        'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'role_id' => $base->id,
                        'role_name' => $base->name, 'effective_from' => now()->toDateString(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                $this->audit->log('access.role_grant_expired', 'user_role_grant', $grant->id,
                    ['roles' => $before], ['roles' => $user->fresh()->roles->pluck('name')->all()], 'บทบาทหมดอายุ '.$grant->expires_at);
            });
        }

        return $due->count();
    }

    private function date(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 10);
    }
}
