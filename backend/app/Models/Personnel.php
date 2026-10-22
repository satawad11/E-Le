<?php

namespace App\Models;

use App\Authz\Contracts\AuthzResource;
use App\Authz\Contracts\ScopedByAuthz;
use App\Events\PersonnelStatusChanged;
use App\Models\Concerns\AuthorizesByPersonnel;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * บุคลากร (CORE-02) — แหล่งความจริงของบัญชีและสิทธิ์ตามตำแหน่ง
 *
 * ข้อมูลที่ส่งออก API ต้องผ่าน PersonnelPresenter (R-03) เพื่อปิดบังตามชั้นความลับ
 */
class Personnel extends Model implements AuthzResource, ScopedByAuthz
{
    use AuthorizesByPersonnel, HasFactory, HasPublicUuid;

    public const STATUSES = ['pending', 'active', 'study_leave', 'seconded', 'suspended', 'separated'];

    protected $table = 'personnel';

    protected $fillable = [
        'personnel_code', 'title', 'first_name', 'last_name', 'first_name_en', 'last_name_en',
        'personnel_type_id', 'academic_standing', 'status', 'started_at', 'separated_at', 'separation_reason', 'photo_path',
    ];

    protected $casts = ['started_at' => 'date', 'separated_at' => 'date'];

    protected $hidden = ['id', 'personnel_type_id'];

    protected $appends = ['full_name'];

    protected static function booted(): void
    {
        // JML (R-05): สถานะเปลี่ยนจากทางใดก็ตาม → บัญชีปรับตาม หลัง commit
        static::updated(function (Personnel $personnel): void {
            if ($personnel->wasChanged('status')) {
                $from = $personnel->getOriginal('status');
                DB::afterCommit(fn () => PersonnelStatusChanged::dispatch($personnel, $from));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getFullNameAttribute(): string
    {
        return trim(($this->title ?? '').($this->first_name ?? '').' '.($this->last_name ?? ''));
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(PersonnelType::class, 'personnel_type_id');
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(PersonnelProfile::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function educations(): HasMany
    {
        return $this->hasMany(PersonnelEducation::class);
    }

    public function trainings(): HasMany
    {
        return $this->hasMany(PersonnelTraining::class);
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(PersonnelLicense::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PersonnelDocument::class);
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(PersonnelChangeRequest::class);
    }

    public function authzPersonnelId(): ?int
    {
        return $this->id;
    }

    public function authzPersonnelColumn(): string
    {
        return 'id';
    }

    public function authzMakerUserId(): ?int
    {
        return null;
    }
}
