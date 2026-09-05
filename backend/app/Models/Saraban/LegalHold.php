<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * การอายัดเอกสารหนึ่งครั้ง (SoT §25)
 *
 * **การอายัดชนะกำหนดการทำลายเสมอ** — เอกสารที่อยู่ระหว่างคดีความหรือการสอบสวน
 * ทำลายไม่ได้ แม้ครบกำหนดและได้รับอนุมัติมาก่อนแล้วก็ตาม
 *
 * แถวที่ปลดแล้วไม่ถูกลบ เพราะต้องตอบได้ว่าเอกสารเคยถูกอายัดช่วงใดด้วยเหตุใด
 */
#[Fillable([
    'uuid', 'document_id', 'reference', 'reason',
    'placed_at', 'placed_by', 'released_at', 'released_by', 'release_note',
])]
class LegalHold extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_legal_holds';

    protected function casts(): array
    {
        return ['placed_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function placer()
    {
        return $this->belongsTo(User::class, 'placed_by');
    }
}
