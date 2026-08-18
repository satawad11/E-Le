<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Overtime\OvertimeTimeRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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

    protected $appends = ['full_name_with_title', 'role', 'role_id', 'personnel_uuid', 'personnel_code', 'personnel_status', 'position'];

    public function getPersonnelUuidAttribute(): ?string
    {
        return $this->personnel?->uuid;
    }

    public function getPersonnelCodeAttribute(): ?string
    {
        return $this->personnel?->personnel_code ?? $this->profile?->personnel_code;
    }

    public function getPersonnelStatusAttribute(): ?string
    {
        return $this->personnel?->status ?? $this->profile?->status;
    }

    public function getPositionAttribute(): ?string
    {
        return $this->currentAssignment?->position ?? $this->profile?->position;
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

    public function currentAssignment()
    {
        return $this->hasOne(PersonnelAssignment::class)
            ->where(function ($q) {
                $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', today());
            })
            ->whereDate('effective_from', '<=', today())
            ->latest('effective_from');
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

    public function personnel()
    {
        return $this->hasOne(Personnel::class);
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

    public function overtimeTimeRecords()
    {
        return $this->hasMany(OvertimeTimeRecord::class);
    }
}
