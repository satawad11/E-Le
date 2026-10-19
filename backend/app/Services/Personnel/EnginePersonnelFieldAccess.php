<?php

namespace App\Services\Personnel;

use App\Authz\PermissionEngine;
use App\Contracts\Personnel\PersonnelFieldAccess;
use App\Models\Personnel;
use App\Models\User;

/**
 * ผูก PersonnelFieldAccess กับ PermissionEngine (R-04 แทน DenyByDefaultPersonnelFieldAccess)
 *
 * ชั้นที่เห็น = public เสมอ
 *             + internal เมื่อมี `personnel.profile.view` ครอบคลุมบุคลากรคนนั้น
 *             + confidential เมื่อมี `personnel.profile.view_confidential` ครอบคลุม
 * sensitive ไม่อยู่ในชั้นที่เห็นเสมอ — เปิดดูผ่าน `personnel.profile.reveal` พร้อมเหตุผลเท่านั้น
 */
class EnginePersonnelFieldAccess implements PersonnelFieldAccess
{
    public function __construct(private readonly PermissionEngine $engine) {}

    public function visibleClasses(User $actor, Personnel $personnel): array
    {
        $classes = ['public'];

        if ($this->engine->allows($actor, 'personnel.profile.view', $personnel)) {
            $classes[] = 'internal';
        }
        if ($this->engine->allows($actor, 'personnel.profile.view_confidential', $personnel)) {
            $classes[] = 'confidential';
        }

        return $classes;
    }

    public function canReveal(User $actor, Personnel $personnel): bool
    {
        return $this->engine->allows($actor, 'personnel.profile.reveal', $personnel);
    }

    public function canEditAsHr(User $actor, Personnel $personnel): bool
    {
        return $this->engine->allows($actor, 'personnel.profile.edit', $personnel);
    }

    public function canReviewChanges(User $actor, Personnel $personnel): bool
    {
        return $this->engine->allows($actor, 'personnel.change_request.review', $personnel);
    }
}
