<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * ทะเบียนตำแหน่ง (ORG-ACCESS-02)
 *
 * ตำแหน่ง **ไม่ให้สิทธิ์ใด ๆ** — สิทธิ์มาจากบทบาทและการมอบหมายหน่วยงาน
 * (docs/ORG-POSITION-ACCESS-MAPPING.md หัวข้อ 1) ไม่ลบแถว ใช้ปิดใช้งานแทน
 * เพราะ `personnel_assignments` ในอดีตยังอ้างถึงอยู่
 */
class Position extends Model
{
    use HasPublicUuid;

    protected $fillable = ['code', 'name', 'position_group_id', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    protected $hidden = ['id', 'position_group_id'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function group()
    {
        return $this->belongsTo(PositionGroup::class, 'position_group_id');
    }
}
