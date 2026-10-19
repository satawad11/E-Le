<?php

namespace App\Models;

use App\Authz\AuthzScope;
use App\Authz\Contracts\AuthzResource;
use App\Authz\Contracts\ScopedByAuthz;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * หน่วยงานในต้นไม้องค์กร (CORE-01)
 *
 * `path` เป็น materialized path (`/1/4/17/`) และ `depth` คือความลึกจริง
 * การคำนวณ path ตอนย้าย parent ทำใน OrgTree service (R-03) ภายใน transaction
 * ปิดหน่วยด้วย `effective_to` ไม่ลบ
 */
class OrgUnit extends Model implements AuthzResource, ScopedByAuthz
{
    use HasPublicUuid;

    protected $fillable = ['parent_id', 'org_unit_type_id', 'code', 'name', 'path', 'depth', 'sort_order', 'effective_from', 'effective_to'];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'depth' => 'integer', 'sort_order' => 'integer'];

    protected $hidden = ['id', 'parent_id', 'org_unit_type_id'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(OrgUnitType::class, 'org_unit_type_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /** @param  Builder<OrgUnit>  $query */
    public function scopeEffectiveOn(Builder $query, \DateTimeInterface|string $date): Builder
    {
        return $query->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    public function authzOwnerPersonnelId(): ?int
    {
        return null;
    }

    /** @return array<int, int> */
    public function authzOrgUnitIds(): array
    {
        return [$this->id];
    }

    public function authzMakerUserId(): ?int
    {
        return null;
    }

    public function applyAuthzScope(Builder $query, AuthzScope $scope): Builder
    {
        if ($scope->all) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($scope) {
            $q->whereRaw('1 = 0');
            if ($scope->unitIds !== []) {
                $q->orWhereIn($q->getModel()->qualifyColumn('id'), $scope->unitIds);
            }
            foreach ($scope->subtreePaths as $path) {
                $q->orWhere($q->getModel()->qualifyColumn('path'), 'like', $path.'%');
            }
        });
    }
}
