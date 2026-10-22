<?php

namespace App\Models\Authz;

use App\Authz\Contracts\AuthzResource;
use App\Models\Assignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * รายการในรอบทบทวนสิทธิ์ — subject เป็น role_grant หรือ permission_override
 *
 * AuthzResource (R-05): ผู้ยืนยันคือผู้ถือ `access.review.confirm` ที่ครอบคลุมหน่วยหลักของเจ้าของสิทธิ์
 * · `not_own` กันยืนยันสิทธิ์ของตนเอง
 */
class AccessReviewItem extends Model implements AuthzResource
{
    public const SUBJECT_ROLE_GRANT = 'role_grant';

    public const SUBJECT_OVERRIDE = 'permission_override';

    public const CONFIRMED = 'confirmed';

    public const REVOKED = 'revoked';

    /** ไม่มีผู้ยืนยันภายในกำหนด → ถอนอัตโนมัติ */
    public const EXPIRED = 'expired';

    protected $fillable = ['access_review_id', 'subject_type', 'subject_id', 'user_id', 'reviewer_id', 'decision', 'decided_at', 'note'];

    protected $casts = ['decided_at' => 'datetime'];

    public function review(): BelongsTo
    {
        return $this->belongsTo(AccessReview::class, 'access_review_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function subject(): RoleGrant|PermissionOverride|null
    {
        return $this->subject_type === self::SUBJECT_ROLE_GRANT
            ? RoleGrant::with('template')->find($this->subject_id)
            : PermissionOverride::with('permission')->find($this->subject_id);
    }

    public function authzOwnerPersonnelId(): ?int
    {
        return $this->user?->personnel_id;
    }

    /** @return array<int, int> */
    public function authzOrgUnitIds(): array
    {
        $personnelId = $this->user?->personnel_id;
        if ($personnelId === null) {
            return [];
        }

        return Assignment::where('personnel_id', $personnelId)
            ->where('kind', Assignment::KIND_PRIMARY)
            ->effectiveOn(today()->toDateString())
            ->pluck('org_unit_id')->map(fn ($id) => (int) $id)->all();
    }

    public function authzMakerUserId(): ?int
    {
        return null;
    }
}
