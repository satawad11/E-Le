<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'year',
        'start_date',
        'end_date',
        'is_current',
    ];

    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'is_current'  => 'boolean',
    ];

    /**
     * Get the current active fiscal year.
     */
    public static function current()
    {
        return static::where('is_current', true)->first();
    }

    /**
     * Scope to order by year descending.
     */
    public function scopeLatestYear($query)
    {
        return $query->orderBy('year', 'desc');
    }
}
