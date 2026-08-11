<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PersonnelType extends Model
{
    protected $fillable = ['code', 'name', 'description', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
    public function policies() { return $this->hasMany(LeavePolicy::class); }
    public function users() { return $this->hasMany(User::class); }
}
