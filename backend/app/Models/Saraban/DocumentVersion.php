<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * เวอร์ชันของหนังสือ — ต้นฉบับและทุกฉบับแก้ไขอยู่คนละแถวเสมอ (SoT §8)
 *
 * `file_hash` เก็บไว้ตั้งแต่ต้นเพราะเป็นหลักฐานที่ลายเซ็นผูกไว้ (SoT §14)
 * และใช้เทียบความต่างระหว่างเวอร์ชัน (SoT §22)
 *
 * `pdf_file_path` เป็น path บน disk `private_uploads` เท่านั้น การเข้าถึงต้องผ่าน
 * `SecureFileController` ตามสัญญา INT-01 §1.12 — ห้ามเสิร์ฟไฟล์เป็นสาธารณะ
 */
#[Fillable([
    'uuid', 'document_id', 'version_no', 'stage',
    'pdf_file_path', 'file_hash', 'page_count', 'created_by',
])]
class DocumentVersion extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_document_versions';

    public const UPDATED_AT = null;

    public const STAGE_ORIGINAL = 'original';

    public const STAGE_REVISED = 'revised';

    public const STAGE_REVIEWED = 'reviewed';

    public const STAGE_ENDORSED = 'endorsed';

    public const STAGE_SIGNED = 'signed';

    public const STAGE_FINAL = 'final';

    /** @return array<int,string> */
    public static function stages(): array
    {
        return [
            self::STAGE_ORIGINAL, self::STAGE_REVISED, self::STAGE_REVIEWED,
            self::STAGE_ENDORSED, self::STAGE_SIGNED, self::STAGE_FINAL,
        ];
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
