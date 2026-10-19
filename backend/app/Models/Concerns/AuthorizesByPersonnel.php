<?php

namespace App\Models\Concerns;

use App\Authz\AuthzScope;
use App\Models\Assignment;
use Illuminate\Database\Eloquent\Builder;

/**
 * resource ที่ขอบเขตสิทธิ์ตัดสินจาก "บุคลากรเจ้าของ" — หน่วยของรายการคือหน่วยต้นสังกัด (assignment primary) ที่มีผลวันนี้
 *
 * model ที่ใช้ trait ต้องมีเมธอด `authzPersonnelId(): ?int` และระบุคอลัมน์บุคลากรด้วย `authzPersonnelColumn()`
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
        if ($scope->all) {
            return $query;
        }

        $column = $query->getModel()->qualifyColumn($this->authzPersonnelColumn());
        $today = today()->toDateString();

        return $query->where(function (Builder $outer) use ($scope, $column, $today) {
            if ($scope->ownPersonnelId !== null) {
                $outer->orWhere($column, $scope->ownPersonnelId);
            }
            if ($scope->unitIds !== [] || $scope->subtreePaths !== []) {
                $outer->orWhereIn($column, function ($sub) use ($scope, $today) {
                    $sub->select('assignments.personnel_id')->from('assignments')
                        ->join('org_units', 'org_units.id', '=', 'assignments.org_unit_id')
                        ->where('assignments.kind', Assignment::KIND_PRIMARY)
                        ->whereDate('assignments.effective_from', '<=', $today)
                        ->where(fn ($q) => $q->whereNull('assignments.effective_to')->orWhereDate('assignments.effective_to', '>=', $today))
                        ->where(function ($units) use ($scope) {
                            if ($scope->unitIds !== []) {
                                $units->orWhereIn('org_units.id', $scope->unitIds);
                            }
                            foreach ($scope->subtreePaths as $path) {
                                $units->orWhere('org_units.path', 'like', $path.'%');
                            }
                        });
                });
            }
        });
    }
}
