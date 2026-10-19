<?php

namespace App\Models\Concerns;

use App\Authz\AuthzScope;
use App\Models\Assignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * resource ที่ขอบเขตสิทธิ์ตัดสินจาก "บุคลากรเจ้าของ" — หน่วยของรายการคือหน่วยต้นสังกัด (assignment primary) ที่มีผลวันนี้
 *
 * model ที่ใช้ trait ต้องมีเมธอด `authzPersonnelId(): ?int` และระบุคอลัมน์บุคลากรด้วย `authzPersonnelColumn()`
 * การกรองรายการ (applyAuthzScope) ใช้กติกาเดียวกับ PermissionEngine::can() ทุกข้อ รวมหน่วยที่ถูก deny
 */
trait AuthorizesByPersonnel
{
    public function authzOwnerPersonnelId(): ?int
    {
        return $this->authzPersonnelId();
    }

    /** @return array<int, int> */
    public function authzOrgUnitIds(): array
    {
        $personnelId = $this->authzPersonnelId();
        if ($personnelId === null) {
            return [];
        }

        return Assignment::where('personnel_id', $personnelId)
            ->where('kind', Assignment::KIND_PRIMARY)
            ->effectiveOn(today()->toDateString())
            ->pluck('org_unit_id')->map(fn ($id) => (int) $id)->all();
    }

    public function applyAuthzScope(Builder $query, AuthzScope $scope): Builder
    {
        if ($scope->isUnrestricted()) {
            return $query;
        }

        $column = $query->getModel()->qualifyColumn($this->authzPersonnelColumn());

        return $query->where(function (Builder $outer) use ($scope, $column) {
            $outer->whereRaw('1 = 0');

            foreach ($scope->terms as $term) {
                $outer->orWhere(function (Builder $q) use ($term, $column) {
                    match ($term['type']) {
                        'all' => $q->whereRaw('1 = 1'),
                        'own' => $q->where($column, $term['personnel_id']),
                        'units' => $q->whereIn($column, $this->primaryPersonnelIn(fn (QueryBuilder $u) => $u->whereIn('org_units.id', $term['unit_ids']))),
                        'subtree' => $q->whereIn($column, $this->primaryPersonnelIn(fn (QueryBuilder $u) => $this->pathsLike($u, $term['paths']))),
                    };

                    if ($term['excluded_paths'] !== []) {
                        $q->whereNotIn($column, $this->primaryPersonnelIn(fn (QueryBuilder $u) => $this->pathsLike($u, $term['excluded_paths'])));
                    }
                });
            }
        });
    }

    /** subquery: บุคลากรที่มีตำแหน่งหลักวันนี้ในหน่วยตามเงื่อนไข */
    private function primaryPersonnelIn(callable $unitFilter): \Closure
    {
        $today = today()->toDateString();

        return function (QueryBuilder $sub) use ($unitFilter, $today) {
            $sub->select('assignments.personnel_id')->from('assignments')
                ->join('org_units', 'org_units.id', '=', 'assignments.org_unit_id')
                ->where('assignments.kind', Assignment::KIND_PRIMARY)
                ->whereDate('assignments.effective_from', '<=', $today)
                ->where(fn ($q) => $q->whereNull('assignments.effective_to')->orWhereDate('assignments.effective_to', '>=', $today))
                ->where(fn (QueryBuilder $units) => $unitFilter($units));
        };
    }

    /** @param  array<int, string>  $paths */
    private function pathsLike(QueryBuilder $query, array $paths): void
    {
        $query->where(function (QueryBuilder $q) use ($paths) {
            foreach ($paths as $path) {
                $q->orWhere('org_units.path', 'like', $path.'%');
            }
        });
    }
}
