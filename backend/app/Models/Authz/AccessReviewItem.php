<?php

namespace App\Models\Authz;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * รายการในรอบทบทวนสิทธิ์ — subject เป็น role_grant หรือ permission_override
 */
class AccessReviewItem extends Model
{
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
}
