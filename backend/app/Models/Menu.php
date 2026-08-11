<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;

class Menu extends Model
{
    use HasFactory;

    protected $table = 'menus';

    protected $fillable = [
        'code',
        'title_th',
        'title_en',
        'module',
        'icon',
        'route_name',
        'route_path',
        'component_path',
        'parent_id',
        'sort_order',
        'is_active',
        'is_external',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_external' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function parent()
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Menu::class, 'parent_id')->orderBy('sort_order', 'asc');
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'menu_permissions', 'menu_id', 'permission_id');
    }
}
