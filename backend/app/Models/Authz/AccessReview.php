<?php

namespace App\Models\Authz;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * รอบทบทวนสิทธิ์พิเศษ (CORE-04 / CORE-03 §นโยบาย) — รายภาคเรียน
 */
class AccessReview extends Model
{
    use HasPublicUuid;

    protected $fillable = ['name', 'period_start', 'period_end', 'due_at', 'status', 'created_by', 'closed_at'];

    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'due_at' => 'date', 'closed_at' => 'datetime'];

    protected $hidden = ['id', 'created_by'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function items(): HasMany
    {
        return $this->hasMany(AccessReviewItem::class);
    }
}
