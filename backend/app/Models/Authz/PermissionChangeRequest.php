<?php

namespace App\Models\Authz;

use App\Authz\Contracts\AuthzResource;
use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * คำขอเปลี่ยนสิทธิ์ความเสี่ยงสูงที่ต้องให้ผู้ดูแลคนที่สองยืนยัน (`two_person`, CORE-04)
 */
class PermissionChangeRequest extends Model implements AuthzResource
{
    use HasPublicUuid;

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected $fillable = [
        'target', 'diff', 'affected_user_count', 'reason', 'requested_by', 'approved_by',
        'status', 'decided_at', 'decision_note',
    ];

    protected $casts = ['target' => 'array', 'diff' => 'array', 'affected_user_count' => 'integer', 'decided_at' => 'datetime'];

    protected $hidden = ['id', 'requested_by', 'approved_by'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function authzOwnerPersonnelId(): ?int
    {
        return $this->requester?->personnel_id;
    }

    /** @return array<int, int> */
    public function authzOrgUnitIds(): array
    {
        return [];
    }

    public function authzMakerUserId(): ?int
    {
        return $this->requested_by;
    }
}
