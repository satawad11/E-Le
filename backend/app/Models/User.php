<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'telegram_chat_id', 'password', 'is_active', 'department_id', 'role_id', 'custom_permissions', 'work_start_time', 'work_end_time'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'custom_permissions' => 'array',
            'phone' => 'encrypted',
        ];
    }

    protected $appends = ['full_name_with_title', 'role'];

    public function getFullNameWithTitleAttribute()
    {
        $title = $this->relationLoaded('profile') && $this->profile ? $this->profile->title : '';
        if ($title && !str_starts_with($this->name, $title)) {
            // Add space if missing? The user asked for "นายธราเชฏฐ์" or "นาย ธราเชฏฐ์"? 
            // In Thai, title and name are usually adjacent or separated by a space.
            // Image 1: "ธราเชฏฐ์ สุคนธ์" in dropdown "นายธราเชฏฐ์ สุคนธ์". Let's do adjacent.
            return $title . $this->name;
        }
        return $this->name;
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function getRoleAttribute()
    {
        return $this->roles->first();
    }



    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaves()
    {
        return $this->hasMany(Leave::class);
    }

    public function delegations()
    {
        return $this->hasMany(UserDelegation::class, 'user_id');
    }

    public function delegatedToMe()
    {
        return $this->hasMany(UserDelegation::class, 'delegate_id');
    }
}
