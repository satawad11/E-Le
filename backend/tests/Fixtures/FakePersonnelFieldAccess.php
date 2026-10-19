<?php

namespace Tests\Fixtures;

use App\Contracts\Personnel\PersonnelFieldAccess;
use App\Models\Personnel;
use App\Models\User;

/**
 * ตัวแทน PermissionEngine สำหรับเทสต์ระดับ service ใน R-03 — กำหนดสิทธิ์รายผู้ใช้ได้ตรง ๆ
 */
class FakePersonnelFieldAccess implements PersonnelFieldAccess
{
    /** @var array<int, array{classes?: array<int, string>, reveal?: bool, hr?: bool, review?: bool}> */
    public array $grants = [];

    /** @param  array{classes?: array<int, string>, reveal?: bool, hr?: bool, review?: bool}  $grant */
    public function grant(User $user, array $grant): self
    {
        $this->grants[$user->id] = $grant;

        return $this;
    }

    public function visibleClasses(User $actor, Personnel $personnel): array
    {
        return $this->grants[$actor->id]['classes'] ?? ['public'];
    }

    public function canReveal(User $actor, Personnel $personnel): bool
    {
        return $this->grants[$actor->id]['reveal'] ?? false;
    }

    public function canEditAsHr(User $actor, Personnel $personnel): bool
    {
        return $this->grants[$actor->id]['hr'] ?? false;
    }

    public function canReviewChanges(User $actor, Personnel $personnel): bool
    {
        return $this->grants[$actor->id]['review'] ?? false;
    }
}
