<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrgUnit extends Model
{
    protected $fillable = ['uuid', 'parent_id', 'code', 'name', 'type', 'effective_from', 'effective_to', 'is_active'];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'is_active' => 'boolean'];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
