<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'telegram_chat_id', 'line_user_id', 'notification_channel', 'password', 'is_active', 'department_id', 'personnel_type_id', 'custom_permissions', 'work_start_time', 'work_end_time'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_active' => 'boolean', 'custom_permissions' => 'array', 'phone' => 'encrypted'];
    }

    protected $appends = ['full_name_with_title', 'role', 'role_id'];

    public function getFullNameWithTitleAttribute()
    {
        $title = $this->relationLoaded('profile') && $this->profile ? $this->profile->title : '';

        return $title && ! str_starts_with($this->name, $title) ? $title.$this->name : $this->name;
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function personnelType()
    {
        return $this->belongsTo(PersonnelType::class);
    }

    public function getRoleAttribute()
    {
        return $this->roles->first();
    }

    public function getRoleIdAttribute()
    {
        return $this->roles->first()?->id;
    }

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function leaveBalances()
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaves()
    {
        return $this->hasMany(Leave::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }
}
