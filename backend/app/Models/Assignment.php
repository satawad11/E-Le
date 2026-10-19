<?php

namespace App\Models;

use App\Authz\Contracts\AuthzResource;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * การดำรงตำแหน่ง (CORE-02) — สิทธิ์ผูกกับ assignment ไม่ผูกกับคน (PROJECT_RULES ข้อ 2)
 *
 * มีวันที่มีผล ห้ามเขียนทับ: แก้โดยปิดแถวเดิม (`effective_to`, `ended_by`, `end_reason`) แล้วเพิ่มแถวใหม่
 * กฎ primary ซ้อน / management ซ้อน และ event `AssignmentChanged` อยู่ใน AssignmentService (R-03)
 */
class Assignment extends Model implements AuthzResource
{
    use HasPublicUuid;

    public const KIND_PRIMARY = 'primary';

    public const KIND_MANAGEMENT = 'management';

    public const KIND_DUTY = 'duty';

    public const KIND_ACTING = 'acting';

    public const KINDS = [self::KIND_PRIMARY, self::KIND_MANAGEMENT, self::KIND_DUTY, self::KIND_ACTING];

    protected $fillable = [
        'personnel_id', 'kind', 'org_unit_id', 'position_id', 'acting_for_assignment_id',
        'order_number', 'order_date', 'effective_from', 'effective_to', 'ended_by', 'end_reason', 'created_by',
    ];

    protected $casts = ['order_date' => 'date', 'effective_from' => 'date', 'effective_to' => 'date'];

    protected $hidden = ['id', 'personnel_id', 'org_unit_id', 'position_id', 'acting_for_assignment_id', 'ended_by', 'created_by'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function actingFor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'acting_for_assignment_id');
    }

    /** @param  Builder<Assignment>  $query */
    public function scopeEffectiveOn(Builder $query, \DateTimeInterface|string $date): Builder
    {
        return $query->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    public function authzOwnerPersonnelId(): ?int
    {
        return $this->personnel_id;
    }

    /** @return array<int, int> */
    public function authzOrgUnitIds(): array
    {
        return [$this->org_unit_id];
    }

    public function authzMakerUserId(): ?int
    {
        return $this->created_by;
    }
}
