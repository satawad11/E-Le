<?php

namespace App\Models\Authz;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModuleFeature extends Model
{
    protected $fillable = ['module_id', 'code', 'name', 'sort_order', 'retired_at'];

    protected $casts = ['sort_order' => 'integer', 'retired_at' => 'datetime'];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
    }
}
