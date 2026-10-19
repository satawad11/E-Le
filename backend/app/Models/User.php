<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Overtime\OvertimeTimeRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * บัญชีผู้ใช้ (CORE-03) — บุคลากร ≠ บัญชีผู้ใช้
 *
 * ชื่อ สังกัด และตำแหน่งมาจากข้อมูลบุคลากร (`personnel`, `assignments`) ไม่เก็บซ้ำที่บัญชี
 * สิทธิ์ไม่ได้อยู่ที่บัญชี: คำนวณจาก assignment / role grant / override โดย PermissionEngine (R-04)
 * ระหว่าง R-02–R-03 ยังไม่มี engine ทุกการตรวจสิทธิ์จึงถูกปฏิเสธ (deny by default)
 */
#[Fillable(['personnel_id', 'username', 'email', 'password', 'status', 'account_type'])]
#[Hidden(['id', 'personnel_id', 'password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasPublicUuid, Notifiable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_SEPARATED = 'separated';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'locked_until' => 'datetime',
            'separated_at' => 'date',
            'last_login_at' => 'datetime',
            'is_break_glass' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ($this->locked_until === null || $this->locked_until->isPast());
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function mfaCredentials(): HasMany
    {
        return $this->hasMany(MfaCredential::class);
    }

    public function loginEvents(): HasMany
    {
        return $this->hasMany(LoginEvent::class);
    }

    public function roleGrants(): HasMany
    {
        return $this->hasMany(Authz\RoleGrant::class);
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(Authz\PermissionOverride::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function overtimeTimeRecords(): HasMany
    {
        return $this->hasMany(OvertimeTimeRecord::class);
    }
}
