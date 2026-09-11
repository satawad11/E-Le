<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Overtime\OvertimeTimeRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

// LEGACY-04 (04-6): ถอด `custom_permissions` ออกแล้ว — คอลัมน์ถูกลบไปใน LEGACY-03
// และสิทธิ์รายบุคคลต้องมีแหล่งเดียวคือ pivot ของ Spatie (DB-RISK-009)
#[Fillable(['name', 'email', 'phone', 'telegram_chat_id', 'line_user_id', 'notification_channel', 'password', 'is_active', 'org_unit_id', 'personnel_type_id', 'work_start_time', 'work_end_time'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /*
     * `SoftDeletes` — ลบบัญชีแล้วประวัติการลาต้องไม่หายตาม
     *
     * foreign key ที่ชี้มาที่ตารางนี้ตั้งเป็น CASCADE หลายตัว (`leaves`,
     * `leave_balances`, `attendances`, `leave_approvals`) การลบแถวจริงจึงลบ
     * ทะเบียนการลาของบุคลากรคนนั้นทั้งหมด และลบแถวผู้อนุมัติบนใบลาของคนอื่นด้วย
     * เมื่อลบแบบ soft แถวยังอยู่ CASCADE จึงไม่ทำงานเลย
     */
    use HasApiTokens, HasFactory, HasPublicUuid, HasRoles, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_active' => 'boolean', 'must_change_password' => 'boolean', 'phone' => 'encrypted'];
    }

    protected $appends = ['full_name_with_title', 'role', 'role_id', 'personnel_uuid', 'personnel_code', 'personnel_status', 'position'];

    /*
     * ค่าตั้งต้นของธงบังคับเปลี่ยนรหัสผ่าน
     *
     * ฐานข้อมูลมี default อยู่แล้ว แต่ instance ที่เพิ่งสร้างในหน่วยความจำจะได้ null
     * จนกว่าจะโหลดใหม่ · โค้ดที่อ่านค่านี้เพื่อ "ตัดสินใจ" ต้องไม่เจอ null
     *
     * **ไม่ใส่ใน Fillable โดยตั้งใจ** — เป็นด่านความปลอดภัย ไม่ควรตั้งได้จาก
     * mass assignment ผู้ที่ต้องตั้งใช้ `forceFill()` อย่างชัดเจน
     */
    protected $attributes = ['must_change_password' => false];

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

    /**
     * หน่วยงานต้นสังกัด
     *
     * `department()` เดิมชี้ไปยังโครงหน่วยงานชั้นเดียวที่ถูกถอดออกแล้ว · คงชื่อเดิมไว้
     * เป็นชื่อพ้องเพื่อไม่ให้ผู้เรียกที่ eager-load `user.department` พังทั้งหมดพร้อมกัน
     */
    public function department()
    {
        return $this->orgUnit();
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
