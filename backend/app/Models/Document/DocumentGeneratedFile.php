<?php

namespace App\Models\Document;

use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * ไฟล์เอกสารที่ระบบออกให้จริง
 *
 * เอกสารที่ `FINALIZED` แล้วห้ามแก้ไขหรือเขียนทับไฟล์เดิมโดยเด็ดขาด
 * ต้องการเปลี่ยนต้องออก revision ใหม่ ของเดิมถูกทำเป็น `SUPERSEDED` แต่ยังอยู่ครบ
 * โมเดลนี้บังคับกฎดังกล่าวไว้ในตัวเอง ไม่ฝากความหวังไว้กับผู้เรียก
 */
class DocumentGeneratedFile extends Model
{
    public const STATUS_GENERATED = 'GENERATED';

    public const STATUS_FINALIZED = 'FINALIZED';

    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    /** คอลัมน์ที่ยังแก้ได้หลัง finalize — มีแค่การถูกแทนที่ด้วย revision ใหม่เท่านั้น */
    private const MUTABLE_AFTER_FINALIZE = ['status', 'updated_at'];

    protected $guarded = [];

    /**
     * snapshot มีข้อมูลส่วนบุคคลของต้นทาง (เหตุผลการลา ที่อยู่ เบอร์โทร) และ path คือที่อยู่ไฟล์ภายใน
     * ไม่ต้องออกไปกับ JSON ของหน้ารายการ (DC-01) — โค้ดฝั่งเซิร์ฟเวอร์ยังอ่านได้ตามปกติ
     */
    protected $hidden = ['snapshot', 'file_path'];

    protected $casts = [
        'snapshot' => 'array',
        'generated_at' => 'datetime',
        'finalized_at' => 'datetime',
        'revision' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (DocumentGeneratedFile $file): void {
            $file->uuid ??= (string) Str::uuid();
            $file->generated_at ??= now();
        });

        static::updating(function (DocumentGeneratedFile $file): void {
            if ($file->getOriginal('status') !== self::STATUS_FINALIZED) {
                return;
            }

            $changed = array_keys($file->getDirty());
            $illegal = array_diff($changed, self::MUTABLE_AFTER_FINALIZE);

            if ($illegal !== []) {
                throw new \RuntimeException('เอกสารฉบับสมบูรณ์แก้ไขไม่ได้ ต้องออกฉบับแก้ไข (revision) ใหม่แทน: '.implode(', ', $illegal));
            }

            // ปลายทางเดียวที่ยอมให้เปลี่ยนสถานะคือถูกแทนที่ด้วย revision ใหม่
            if ($file->status !== self::STATUS_SUPERSEDED) {
                throw new \RuntimeException('เอกสารฉบับสมบูรณ์เปลี่ยนสถานะได้เฉพาะเมื่อถูกแทนที่ด้วยฉบับแก้ไขใหม่');
            }
        });

        static::deleting(function (DocumentGeneratedFile $file): void {
            if ($file->status !== self::STATUS_GENERATED) {
                throw new \RuntimeException('ห้ามลบเอกสารฉบับสมบูรณ์ออกจากระบบ');
            }
        });
    }

    public function template()
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function version()
    {
        return $this->belongsTo(DocumentTemplateVersion::class, 'document_template_version_id');
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }
}
