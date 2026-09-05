<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ประเภทหนังสือ — ข้อมูลอ้างอิงที่โมดูลสารบรรณเป็นเจ้าของเอง (SoT §5)
 *
 * `category` เป็นตัวแยกชุดทะเบียนตาม SoT §9 และจะเป็นตัวกำหนดชุดเลขหนังสือใน INT-06
 */
#[Fillable(['uuid', 'code', 'name', 'abbreviation', 'category', 'is_active', 'sort_order'])]
class DocumentType extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_document_types';

    public const CATEGORY_INCOMING = 'incoming';

    public const CATEGORY_OUTGOING = 'outgoing';

    public const CATEGORY_INTERNAL = 'internal';

    public const CATEGORY_ORDER = 'order';

    public const CATEGORY_ANNOUNCEMENT = 'announcement';

    /** @return array<int,string> */
    public static function categories(): array
    {
        return [
            self::CATEGORY_INCOMING,
            self::CATEGORY_OUTGOING,
            self::CATEGORY_INTERNAL,
            self::CATEGORY_ORDER,
            self::CATEGORY_ANNOUNCEMENT,
        ];
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'document_type_id');
    }
}
