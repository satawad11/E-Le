<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * วัตถุที่วางบนหน้าหนังสือ (SoT §14 · INT-10)
 *
 * พิกัดเป็น PDF point จากมุมซ้ายบน — หน่วยเดียวกับที่ `CoordinateConverter`
 * ของ Document Engine เดิมใช้ จึงเรนเดอร์ได้โดยไม่ต้องแปลงระบบพิกัด
 */
#[Fillable([
    'uuid', 'document_version_id', 'page', 'type',
    'x', 'y', 'width', 'height', 'payload', 'created_by',
])]
class DocumentAnnotation extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_document_annotations';

    /** ตราประทับรับหนังสือ (SoT §10) */
    public const TYPE_STAMP = 'stamp';

    public const TYPE_TEXT = 'text';

    public const TYPE_DATE = 'date';

    /**
     * ลายมือเขียนสด — **ไม่ใช่ลายเซ็น** (SoT §15)
     *
     * เก็บเป็นชุดพิกัดของเส้น ไม่ใช่ภาพ จึงเรนเดอร์เป็นเส้นเวกเตอร์ที่ฝั่ง server ได้
     * และไม่ขัดกับข้อห้ามเรื่องภาพจากเบราว์เซอร์ (SoT §14)
     */
    public const TYPE_HANDWRITING = 'handwriting';

    /** ลายเซ็นที่มีผลผูกพัน — ต้องมีหลักฐานการลงนามกำกับเสมอ */
    public const TYPE_SIGNATURE = 'signature';

    public const TYPE_CHECKMARK = 'checkmark';

    /** ความเห็นประกอบการเกษียน */
    public const TYPE_NOTE = 'note';

    public const TYPE_QR = 'qr';

    /** @return array<int,string> */
    public static function types(): array
    {
        return [
            self::TYPE_STAMP, self::TYPE_TEXT, self::TYPE_DATE, self::TYPE_QR,
            self::TYPE_HANDWRITING, self::TYPE_SIGNATURE, self::TYPE_CHECKMARK, self::TYPE_NOTE,
        ];
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'x' => 'float',
            'y' => 'float',
            'width' => 'float',
            'height' => 'float',
        ];
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
