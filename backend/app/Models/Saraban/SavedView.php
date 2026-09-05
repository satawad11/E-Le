<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * มุมมองที่บันทึกไว้ — คิวงานส่วนตัวหรือที่แบ่งปันกัน (SoT §22)
 *
 * **เก็บเงื่อนไขการค้น ไม่ใช่ผลลัพธ์** — ผลลัพธ์ที่แช่ไว้จะล้าสมัยทันทีที่มี
 * หนังสือใหม่เข้ามา และกลายเป็นข้อมูลที่หลอกผู้ใช้ว่างานเสร็จแล้ว
 *
 * **มุมมองที่แบ่งปันไม่ได้แบ่งปันข้อมูล** — คนอื่นเปิดมุมมองเดียวกันจะเห็นเฉพาะ
 * หนังสือที่ตนมีสิทธิ์เห็นอยู่แล้ว เพราะตัวกรองถูกนำไปใช้กับ query ที่ผ่าน
 * `DocumentPolicy` ตามปกติ
 */
#[Fillable(['uuid', 'name', 'resource', 'filters', 'is_shared', 'owner_id'])]
class SavedView extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_saved_views';

    public const RESOURCE_DOCUMENTS = 'documents';

    public const RESOURCE_INBOX = 'inbox';

    protected function casts(): array
    {
        return ['filters' => 'array', 'is_shared' => 'boolean'];
    }

    /** @return array<int,string> */
    public static function resources(): array
    {
        return [self::RESOURCE_DOCUMENTS, self::RESOURCE_INBOX];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
