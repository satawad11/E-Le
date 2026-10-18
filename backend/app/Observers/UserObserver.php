<?php

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    /**
     * เปลี่ยนรหัสผ่าน หรือบัญชีไม่อยู่ในสถานะ active แล้ว → ตัด token ทั้งหมดทันที (CORE-03)
     */
    public function updated(User $user): void
    {
        if ($user->wasChanged('password') || ($user->wasChanged('status') && $user->status !== User::STATUS_ACTIVE)) {
            $user->tokens()->delete();
        }
    }
}
