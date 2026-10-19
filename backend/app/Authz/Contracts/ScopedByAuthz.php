<?php

namespace App\Authz\Contracts;

use App\Authz\AuthzScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * model ที่ PermissionEngine::scopeQuery() กรองรายการตาม scope ได้
 */
interface ScopedByAuthz
{
    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function applyAuthzScope(Builder $query, AuthzScope $scope): Builder;
}
