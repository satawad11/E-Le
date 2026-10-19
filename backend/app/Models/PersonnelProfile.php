<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ข้อมูลส่วนบุคคลของบุคลากร (CORE-02, 1:1)
 *
 * ฟิลด์ชั้น `sensitive` เข้ารหัสที่ฐานข้อมูลด้วย cast `encrypted` (PROJECT_RULES ข้อ 11)
 * ค้นหาเลขประจำตัวประชาชนผ่าน `national_id_hash` ซึ่งตั้งค่าอัตโนมัติเมื่อกำหนด `national_id`
 * ฟิลด์ sensitive ถูกซ่อนจาก serialization — การส่งออกต้องผ่าน PersonnelPresenter / SensitiveReveal (R-03)
 */
class PersonnelProfile extends Model
{
    public const SENSITIVE = ['national_id', 'address_registered', 'address_current', 'bank_name', 'bank_account', 'health_notes'];

    protected $fillable = [
        'personnel_id', 'email_work', 'phone_mobile', 'birth_date',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
        'national_id', 'address_registered', 'address_current', 'bank_name', 'bank_account', 'health_notes',
    ];

    protected $hidden = [
        'id', 'personnel_id', 'national_id', 'national_id_hash', 'address_registered', 'address_current',
        'bank_name', 'bank_account', 'health_notes',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'national_id' => 'encrypted',
            'address_registered' => 'encrypted',
            'address_current' => 'encrypted',
            'bank_name' => 'encrypted',
            'bank_account' => 'encrypted',
            'health_notes' => 'encrypted',
        ];
    }

    public static function hashNationalId(string $nationalId): string
    {
        return hash('sha256', preg_replace('/\D/', '', $nationalId).config('app.key'));
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            if ($profile->isDirty('national_id')) {
                $profile->national_id_hash = $profile->national_id ? self::hashNationalId($profile->national_id) : null;
            }
        });
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }
}
