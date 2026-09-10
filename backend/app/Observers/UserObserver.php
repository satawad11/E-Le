<?php

namespace App\Observers;

use App\Models\User;
use App\Services\PersonnelSyncService;

class UserObserver
{
    /*
     * เดิมมี `saving()` ที่ประคอง `department_id` กับ `org_unit_id` ให้ตรงกันสองทาง
     * เมื่อผังองค์กรเหลือชุดเดียว (`org_units`) ก็ไม่มีอะไรให้ประคองอีก — ผู้ใช้มี
     * หน่วยงานต้นสังกัดเดียวและเขียนตรงเข้า `org_unit_id`
     */

    public function saved(User $user): void
    {
        app(PersonnelSyncService::class)->syncFromUser($user);
    }

    public function updated(User $user): void
    {
        if ($user->wasChanged('password') || ($user->wasChanged('is_active') && ! $user->is_active)) {
            $user->tokens()->delete();
        }
    }
}
