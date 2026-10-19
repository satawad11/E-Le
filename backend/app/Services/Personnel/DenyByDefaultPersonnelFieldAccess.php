<?php

namespace App\Services\Personnel;

use App\Contracts\Personnel\PersonnelFieldAccess;
use App\Models\Personnel;
use App\Models\User;

/**
 * การตัดสินระหว่างยังไม่มี PermissionEngine (R-03) — deny by default
 *
 * - เจ้าของข้อมูลเห็นข้อมูลของตนเองถึงชั้น confidential และเปิดดูชั้น sensitive ของตนเองได้ (มีบันทึก)
 * - ผู้อื่นเห็นเฉพาะชั้น public · แก้ไขหรือพิจารณาคำขอแทนผู้อื่นไม่ได้
 */
class DenyByDefaultPersonnelFieldAccess implements PersonnelFieldAccess
{
    public function visibleClasses(User $actor, Personnel $personnel): array
    {
        return $this->isSelf($actor, $personnel) ? ['public', 'internal', 'confidential'] : ['public'];
    }

    public function canReveal(User $actor, Personnel $personnel): bool
    {
        return $this->isSelf($actor, $personnel);
    }

    public function canEditAsHr(User $actor, Personnel $personnel): bool
    {
        return false;
    }

    public function canReviewChanges(User $actor, Personnel $personnel): bool
    {
        return false;
    }

    private function isSelf(User $actor, Personnel $personnel): bool
    {
        return $actor->personnel_id !== null && $actor->personnel_id === $personnel->id;
    }
}
