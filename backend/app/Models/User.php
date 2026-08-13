<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

// LEGACY-04 (04-6): ถอด `custom_permissions` ออกแล้ว — คอลัมน์ถูกลบไปใน LEGACY-03
// และสิทธิ์รายบุคคลต้องมีแหล่งเดียวคือ pivot ของ Spatie (DB-RISK-009)
#[Fillable(['name', 'email', 'phone', 'telegram_chat_id', 'line_user_id', 'notification_channel', 'password', 'is_active', 'department_id', 'personnel_type_id', 'work_start_time', 'work_end_time'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasPublicUuid, HasRoles, Notifiable;

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_active' => 'boolean', 'phone' => 'encrypted'];
    }

    protected $appends = ['full_name_with_title', 'role', 'role_id'];

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->isDirty('department_id')) {
                $user->org_unit_id = $user->department_id
                    ? Department::find($user->department_id)?->orgUnit?->id
                    : null;
            }
        });

        static::saved(function (User $user): void {
            if ($user->wasRecentlyCreated || $user->wasChanged(['department_id', 'personnel_type_id'])) {
                $current = PersonnelAssignment::where('user_id', $user->id)->whereNull('effective_to')->first();
                if ($current && ($current->org_unit_id !== $user->org_unit_id || $current->personnel_type_id !== $user->personnel_type_id)) {
                    $current->update(['effective_to' => now()->subDay()->toDateString()]);
                    $current = null;
                }
                if (! $current) {
                    PersonnelAssignment::create([
                        'uuid' => (string) Str::uuid(),
                        'user_id' => $user->id,
                        'org_unit_id' => $user->org_unit_id,
                        'personnel_type_id' => $user->personnel_type_id,
                        'position' => null,
                        'effective_from' => now()->toDateString(),
                    ]);
                }
            }
        });

        static::updated(function (User $user) {
            if ($user->wasChanged('password') || ($user->wasChanged('is_active') && ! $user->is_active)) {
                $user->tokens()->delete();
            }
        });
    }

    public function getFullNameWithTitleAttribute()
    {
        $title = $this->relationLoaded('profile') && $this->profile ? $this->profile->title : '';

        return $title && ! str_starts_with($this->name, $title) ? $title.$this->name : $this->name;
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function orgUnit()
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function personnelAssignments()
    {
        return $this->hasMany(PersonnelAssignment::class);
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
