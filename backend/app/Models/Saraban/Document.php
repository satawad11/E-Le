<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * หนังสือ — entity หลักของโมดูลสารบรรณ (SoT §8)
 *
 * ไฟล์ PDF **ไม่ใช่** ตัวเอกสาร แต่เป็นภาพแทนของแต่ละเวอร์ชัน สายงานจึงผูกกับ
 * `document_id + document_version_id` ไม่ใช่ผูกกับไฟล์ และต้นฉบับห้ามถูกเขียนทับ
 *
 * ทุกการอ้างถึงคนและหน่วยงานเป็น id ของระบบกลางเสมอ (`users.id`, `org_units.id`)
 * — ห้ามคัดลอกทะเบียนหลักมาเก็บซ้ำ และห้ามใช้ `department_id` ซึ่งมีหน่วยงานระดับเดียว
 */
#[Fillable([
    'uuid', 'document_type_id', 'direction', 'subject', 'summary',
    'confidentiality', 'urgency', 'status', 'submitted_at', 'issued_at', 'finalized_at',
    'verification_hash', 'content_hash',
    'current_version_id', 'document_number_id',
    'owner_org_unit_id', 'created_by',
])]
class Document extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_documents';

    public const DIRECTION_INCOMING = 'incoming';

    public const DIRECTION_OUTGOING = 'outgoing';

    public const DIRECTION_INTERNAL = 'internal';

    public const STATUS_DRAFT = 'draft';

    /** ลงทะเบียนรับแล้ว (SoT §10) — หนังสือรับเข้าสู่ทะเบียนและได้เลขรับแล้ว */
    public const STATUS_REGISTERED = 'registered';

    /** ลงนามแล้ว — ได้เลขหนังสือและถือเป็นหนังสือราชการสมบูรณ์ (SoT §8) */
    public const STATUS_SIGNED = 'signed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /** เสนอเข้าสู่กระบวนการแล้ว — เนื้อหาแก้ทับไม่ได้อีก (SoT §8) */
    public const STATUS_SUBMITTED = 'submitted';

    public const CONFIDENTIALITY_NORMAL = 'normal';

    public const URGENCY_NORMAL = 'normal';

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'issued_at' => 'datetime', 'finalized_at' => 'datetime'];
    }

    /**
     * INT-19 — หนังสือที่ออกไปแล้วห้ามลบตามปกติ (SoT §25)
     *
     * ระบบไม่มีปุ่มลบหนังสืออยู่แล้ว แต่ **"ไม่มีปุ่ม" ไม่เท่ากับ "ลบไม่ได้"** —
     * วันหนึ่งจะมีคนเพิ่มปุ่มนั้น หรือเรียก `delete()` จาก tinker หรือ seeder
     * ด่านนี้จึงอยู่ที่โมเดล ซึ่งเป็นจุดที่ทุกเส้นทางต้องผ่าน
     *
     * ทางที่ถูกต้องคือกระบวนการทำลายตามระเบียบใน `RetentionService` ซึ่งลบ
     * **ไฟล์** แต่เก็บ **ทะเบียน** ไว้ตรวจสอบได้เสมอ
     */
    protected static function booted(): void
    {
        static::deleting(function (self $document): void {
            $issued = $document->finalized_at !== null
                || $document->document_number_id !== null
                || $document->status === self::STATUS_SIGNED;

            if ($issued) {
                throw new \RuntimeException(
                    'หนังสือที่ออกไปแล้วลบไม่ได้ — ต้องใช้กระบวนการทำลายเอกสารตามระเบียบ (SoT §25)',
                );
            }
        });
    }

    /** ยังเป็นฉบับร่างอยู่หรือไม่ — ตัวตัดสินว่าจะแก้ไขได้ไหม */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** @return array<int,string> */
    public static function directions(): array
    {
        return [self::DIRECTION_INCOMING, self::DIRECTION_OUTGOING, self::DIRECTION_INTERNAL];
    }

    /**
     * ชั้นความลับตามระเบียบงานสารบรรณ เรียงจากเปิดเผยได้ไปหาลับที่สุด
     *
     * @return array<int,string>
     */
    public static function confidentialityLevels(): array
    {
        return ['normal', 'confidential', 'secret', 'top_secret'];
    }

    /**
     * ชั้นความเร็ว เรียงจากปกติไปหาด่วนที่สุด
     *
     * @return array<int,string>
     */
    public static function urgencyLevels(): array
    {
        return ['normal', 'urgent', 'very_urgent', 'most_urgent'];
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class, 'document_id')->orderBy('version_no');
    }

    public function currentVersion()
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /** ต้นฉบับที่ยื่นเข้ามาครั้งแรก — ต้องเข้าถึงได้เสมอแม้ออกหนังสือแล้ว (SoT §8) */
    public function originalVersion()
    {
        return $this->hasOne(DocumentVersion::class, 'document_id')->oldestOfMany('version_no');
    }

    public function ownerOrgUnit()
    {
        return $this->belongsTo(OrgUnit::class, 'owner_org_unit_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** เลขที่หนังสือ — ออกตอนลงนามเสร็จ (INT-08) */
    public function documentNumber()
    {
        return $this->belongsTo(DocumentNumber::class, 'document_number_id');
    }

    /** ทะเบียนรับของหนังสือฉบับนี้ — มีเฉพาะหนังสือรับ (INT-07) */
    public function incomingRegistration()
    {
        return $this->hasOne(IncomingRegistration::class, 'document_id');
    }
}
