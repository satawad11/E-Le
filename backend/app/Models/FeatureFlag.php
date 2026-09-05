<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ธงเปิด-ปิดความสามารถของทั้งระบบ (SoT §21)
 *
 * **ธง ≠ สิทธิ์** — สิทธิ์ตอบว่า "คนนี้ทำสิ่งนี้ได้ไหม" ธงตอบว่า "ความสามารถนี้
 * เปิดอยู่ไหม" · ธงที่เปิดไม่ได้ให้สิทธิ์ใครเพิ่มแม้แต่คนเดียว
 */
#[Fillable(['uuid', 'key', 'name', 'description', 'module', 'enabled', 'updated_by'])]
class FeatureFlag extends Model
{
    use HasPublicUuid;

    protected $table = 'feature_flags';

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
