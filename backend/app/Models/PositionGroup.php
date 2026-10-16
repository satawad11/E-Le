<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * กลุ่มบุคลากรสำหรับรายงาน เช่น ผู้บริหาร / ครู / เจ้าหน้าที่ (ORG-ACCESS-02)
 * ไม่ให้สิทธิ์ใด ๆ — ใช้แสดงผลและจัดกลุ่มรายงานเท่านั้น
 */
class PositionGroup extends Model
{
    use HasPublicUuid;

    protected $fillable = ['code', 'name', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    protected $hidden = ['id'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function positions()
    {
        return $this->hasMany(Position::class);
    }
}
