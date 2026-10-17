<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * แถวในทะเบียนการมอบบทบาท (ACCESS-GOV-01 · C3) — เขียนผ่าน RoleGrantService เท่านั้น
 */
class UserRoleGrant extends Model
{
    use HasPublicUuid;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'expires_at' => 'date', 'ended_at' => 'datetime'];
    }
}
