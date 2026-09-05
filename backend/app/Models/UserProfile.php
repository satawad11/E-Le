<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'id_card_number',
        'id_card_number_hash',
        'date_of_birth',
        'nationality',
        // 'ethnicity' และ 'religion' ถอดออกจาก fillable โดยตั้งใจ
        // เป็นข้อมูลอ่อนไหวตาม ม.26 พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล และระบบไม่ได้นำไปใช้งาน
        'passport_number',
        'passport_issue_date',
        'passport_expiry_date',
        'title',
        'first_name',
        'last_name',
        'middle_name',
        'gender',
        'first_name_en',
        'last_name_en',
        'house_registration_number',
        'house_number',
        'moo',
        'soi',
        'road',
        'province',
        'district',
        'sub_district',
        'zipcode',
        'fax',
        'website',
        'personnel_code',
        'position',
        'education_level',
        'degree',
        'start_date',
        'license_type',
        'license_number',
        'license_issue_date',
        'license_expiry_date',
        'status',
        'profile_photo_path',
        'signature_path',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'passport_issue_date' => 'date',
        'passport_expiry_date' => 'date',
        'start_date' => 'date',
        'license_issue_date' => 'date',
        'license_expiry_date' => 'date',
        'id_card_number' => 'encrypted',
        'passport_number' => 'encrypted',
    ];

    /**
     * เลขประจำตัวประชาชนแบบปิดบัง — เปิดเฉพาะ 4 หลักท้าย
     *
     * SEC-030 — endpoint แบบ "รายการ" ต้องไม่ส่งเลขเต็มของบุคลากรทั้งสถานศึกษา
     * ออกไปพร้อมกันทีเดียว หน้าจอใช้เพียงเพื่อ "ยืนยันว่าใช่คนนี้" ซึ่ง 4 หลักท้าย
     * เพียงพอแล้ว · เลขเต็มยังเปิดได้ที่หน้ารายละเอียดรายคน ซึ่งมีทั้งการตรวจสิทธิ์
     * ขอบเขตหน่วยงาน และบันทึกการเข้าถึงข้อมูลส่วนบุคคลรายครั้ง
     *
     * รูปแบบตามบัตรจริง: 0-0000-00000-00-0
     */
    public function maskedIdCard(): ?string
    {
        $number = preg_replace('/\D/', '', (string) $this->id_card_number);

        if ($number === null || $number === '') {
            return null;
        }

        if (strlen($number) !== 13) {
            // เลขที่ไม่ใช่ 13 หลัก (ข้อมูลเก่า/ต่างชาติ) — ปิดบังทั้งหมดยกเว้น 4 หลักท้าย
            return strlen($number) <= 4
                ? str_repeat('x', strlen($number))
                : str_repeat('x', strlen($number) - 4).substr($number, -4);
        }

        // รูปแบบตามบัตรจริง D-DDDD-DDDDD-DD-D โดยเปิดเฉพาะ 4 หลักท้าย
        return 'x-xxxx-xxxx'.$number[9].'-'.$number[10].$number[11].'-'.$number[12];
    }

    /** 4 หลักท้ายของเลขประจำตัวประชาชน — ใช้ค้นหาในหน้าจอโดยไม่เปิดเลขเต็ม */
    public function idCardLastFour(): ?string
    {
        $number = preg_replace('/\D/', '', (string) $this->id_card_number);

        return $number === null || strlen($number) < 4 ? null : substr($number, -4);
    }

    protected static function booted()
    {
        static::saving(function ($profile) {
            if ($profile->isDirty('id_card_number')) {
                // When accessing $profile->id_card_number, it gets decrypted automatically by the cast.
                // If it's dirty, we update the hash.
                $profile->id_card_number_hash = $profile->id_card_number
                    ? hash('sha256', $profile->id_card_number.config('app.key'))
                    : null;
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
