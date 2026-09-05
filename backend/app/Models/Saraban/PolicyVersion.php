<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * เนื้อหานโยบายหนึ่งเวอร์ชัน (SoT §18)
 *
 * **เวอร์ชันที่เผยแพร่แล้วห้ามแก้** — ถ้าแก้ได้ ประวัติจะโกหก และคำถามว่า
 * "หนังสือฉบับนั้นถูกตัดสินด้วยกติกาอะไร" จะตอบไม่ได้อีกเลย
 * การเปลี่ยนกติกาต้องเป็นการสร้างเวอร์ชันใหม่เสมอ
 */
#[Fillable([
    'uuid', 'policy_id', 'version_no', 'status', 'payload', 'notes',
    'effective_from', 'published_at', 'published_by', 'created_by',
])]
class PolicyVersion extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_policy_versions';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'version_no' => 'integer',
            'effective_from' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        /*
         * ด่านสุดท้ายที่ระดับโมเดล — ไม่พึ่งว่าทุกผู้เรียกจะจำกติกาได้
         * เวอร์ชันที่เผยแพร่แล้วเปลี่ยนได้อย่างเดียวคือถูกเก็บเข้าคลัง (archived)
         */
        static::updating(function (self $version) {
            $original = $version->getOriginal('status');

            if ($original !== self::STATUS_PUBLISHED) {
                return;
            }

            $changed = array_keys($version->getDirty());

            if ($changed !== ['status'] || $version->status !== self::STATUS_ARCHIVED) {
                throw new \RuntimeException('เวอร์ชันนโยบายที่เผยแพร่แล้วแก้ไขไม่ได้ ต้องสร้างเวอร์ชันใหม่');
            }
        });

        static::deleting(function (self $version) {
            if ($version->status !== self::STATUS_DRAFT) {
                throw new \RuntimeException('ลบได้เฉพาะฉบับร่างของนโยบายเท่านั้น');
            }
        });
    }

    public function policy()
    {
        return $this->belongsTo(Policy::class, 'policy_id');
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
