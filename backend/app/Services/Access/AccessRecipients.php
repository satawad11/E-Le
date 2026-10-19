<?php

namespace App\Services\Access;

use App\Models\Assignment;
use App\Models\Authz\RoleGrant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * ผู้รับแจ้งเตือนเรื่องบัญชี — หาจากชุดสิทธิ์และตำแหน่งที่มีผลวันนี้ ไม่ใช้ชื่อ role
 */
class AccessRecipients
{
    /** @return Collection<int, User> งานบุคคล */
    public function hr(): Collection
    {
        return $this->holdersOf((array) config('auth.notify.hr_templates', []));
    }

    /** @return Collection<int, User> ผู้ตรวจสอบ */
    public function auditors(): Collection
    {
        return $this->holdersOf((array) config('auth.notify.auditor_templates', []));
    }

    /** @return Collection<int, User> ผู้อำนวยการ (บริหาร/รักษาการในหน่วยระดับ 1) */
    public function directors(): Collection
    {
        $personnelIds = Assignment::query()
            ->whereIn('kind', [Assignment::KIND_MANAGEMENT, Assignment::KIND_ACTING])
            ->effectiveOn(today()->toDateString())
            ->whereHas('orgUnit.type', fn ($q) => $q->where('level', 1))
            ->pluck('personnel_id');

        return User::whereIn('personnel_id', $personnelIds)->where('status', User::STATUS_ACTIVE)->get();
    }

    /**
     * @param  array<int, string>  $templateCodes
     * @return Collection<int, User>
     */
    private function holdersOf(array $templateCodes): Collection
    {
        if ($templateCodes === []) {
            return collect();
        }

        $today = today()->toDateString();
        $userIds = RoleGrant::query()
            ->whereNull('ended_at')
            ->whereDate('effective_from', '<=', $today)
            ->whereDate('expires_at', '>=', $today)
            ->whereHas('template', fn ($q) => $q->whereIn('code', $templateCodes)->whereNull('retired_at'))
            ->pluck('user_id');

        return User::whereIn('id', $userIds)->where('status', User::STATUS_ACTIVE)->get();
    }
}
