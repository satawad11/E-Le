<?php

namespace App\Services;

use App\Models\OrgUnitManager;
use App\Models\OrgUnitMember;
use App\Models\User;
use App\Models\UserDelegation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * บันทึกบุคลากรพ้นสภาพ (ACCESS-GOV-01)
 *
 * บัญชีไม่ถูกลบ เพราะใบลา การลงเวลา และทะเบียนประวัติยังอ้างถึง — แต่ต้องปิดทุกช่องทางที่ให้อำนาจ
 * ในวันเดียวกัน มิฉะนั้นคนที่ย้ายออกไปแล้วยังอนุมัติใบลาหรือเห็นข้อมูลของหน่วยงานเดิมได้:
 *
 * 1. ระงับบัญชี (UserObserver ยกเลิก token ทั้งหมด)
 * 2. ปิดวาระหัวหน้าหน่วยงานและการประจำงานที่ยังมีผลหรือยังไม่เริ่ม ณ วันพ้นสภาพ
 * 3. เพิกถอนการรักษาราชการแทน/ปฏิบัติราชการแทน ทั้งที่เป็นผู้มอบและผู้รับมอบ
 * 4. ลบขอบเขตสิทธิ์รายบุคคล (permission_scopes)
 *
 * บทบาทและสิทธิ์รายบุคคลคงไว้เป็นหลักฐานว่าเคยมีสิทธิ์อะไร — บัญชีที่ระงับใช้สิทธิ์เหล่านั้นไม่ได้อยู่แล้ว
 * ใบลาที่ผู้นั้นยื่นค้างไว้ไม่ถูกแตะ งานบุคลากรพิจารณาเอง (รายงานจำนวนกลับไป)
 */
class AccountSeparationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{managers_ended:int, memberships_ended:int, delegations_revoked:int, scopes_removed:int, pending_leaves:int}
     */
    public function separate(User $actor, User $user, string $date, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('กรุณาระบุเหตุที่พ้นสภาพ');
        }
        if ($actor->is($user)) {
            throw new InvalidArgumentException('บันทึกการพ้นสภาพของตนเองไม่ได้');
        }
        if ($user->separated_at !== null) {
            throw new InvalidArgumentException('บุคลากรนี้ถูกบันทึกพ้นสภาพแล้ว');
        }

        $date = Carbon::parse($date)->toDateString();

        return DB::transaction(function () use ($actor, $user, $date, $reason) {
            $before = ['is_active' => (bool) $user->is_active, 'separated_at' => null];

            $managers = OrgUnitManager::query()
                ->where('user_id', $user->id)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>', $date))
                ->get();
            foreach ($managers as $manager) {
                // วาระที่ยังไม่เริ่มก็ปิดที่วันเริ่มของมันเอง เพื่อไม่ให้ effective_to มาก่อน effective_from
                $end = $manager->effective_from->toDateString() > $date ? $manager->effective_from->toDateString() : $date;
                $manager->forceFill(['effective_to' => $end, 'ended_by' => $actor->id])->save();
                $this->audit->log('org_unit.manager_ended', 'org_unit_manager', $manager->id, null, ['effective_to' => $end], 'พ้นสภาพ: '.$reason, $actor->id);
            }

            $members = OrgUnitMember::query()
                ->where('user_id', $user->id)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>', $date))
                ->get();
            foreach ($members as $member) {
                $end = $member->effective_from->toDateString() > $date ? $member->effective_from->toDateString() : $date;
                $member->forceFill(['effective_to' => $end, 'ended_by' => $actor->id])->save();
                $this->audit->log('org_unit.member_ended', 'org_unit_member', $member->id, null, ['effective_to' => $end], 'พ้นสภาพ: '.$reason, $actor->id);
            }

            $delegations = UserDelegation::query()
                ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('delegate_id', $user->id))
                ->where('status', '!=', UserDelegation::STATUS_REVOKED)
                ->whereDate('end_date', '>=', $date)
                ->get();
            foreach ($delegations as $delegation) {
                $delegation->forceFill([
                    'status' => UserDelegation::STATUS_REVOKED,
                    'is_active' => false,
                    'revoked_by' => $actor->id,
                    'revoked_at' => now(),
                ])->save();
                $this->audit->log('delegation.revoked', 'user_delegation', $delegation->id, null, ['status' => UserDelegation::STATUS_REVOKED], 'พ้นสภาพ: '.$reason, $actor->id);
            }

            $scopes = DB::table('permission_scopes')
                ->where('model_type', $user->getMorphClass())
                ->where('model_id', $user->id)
                ->get();
            if ($scopes->isNotEmpty()) {
                DB::table('permission_scopes')->whereIn('id', $scopes->pluck('id'))->delete();
                foreach ($scopes as $scope) {
                    $this->audit->log('access.scope.revoked', 'permission_scope', $scope->id, (array) $scope, null, 'พ้นสภาพ: '.$reason, $actor->id);
                }
            }

            $user->forceFill([
                'is_active' => false,
                'separated_at' => $date,
                'separation_reason' => mb_substr($reason, 0, 255),
            ])->save();

            $result = [
                'managers_ended' => $managers->count(),
                'memberships_ended' => $members->count(),
                'delegations_revoked' => $delegations->count(),
                'scopes_removed' => $scopes->count(),
                'pending_leaves' => DB::table('leaves')->where('user_id', $user->id)->where('status', 'Pending')->whereNull('deleted_at')->count(),
            ];

            $this->audit->log('user.separated', $user, null, $before, ['is_active' => false, 'separated_at' => $date] + $result, $reason, $actor->id);

            return $result;
        });
    }

    /** กลับเข้ารับราชการ/ต่อสัญญา — เปิดบัญชีและล้างวันพ้นสภาพ ส่วนวาระหัวหน้าที่ปิดไปต้องแต่งตั้งใหม่ */
    public function reinstate(User $actor, User $user): void
    {
        DB::transaction(function () use ($actor, $user) {
            $before = ['separated_at' => $user->separated_at?->toDateString(), 'separation_reason' => $user->separation_reason];
            $user->forceFill(['is_active' => true, 'separated_at' => null, 'separation_reason' => null])->save();
            $this->audit->log('user.reinstated', $user, null, $before, ['is_active' => true], null, $actor->id);
        });
    }
}
