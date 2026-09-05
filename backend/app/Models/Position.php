<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ตำแหน่งในโครงสร้างองค์กร (INT-03 · RISK-03)
 *
 * เดิมตำแหน่งเก็บเป็นข้อความอิสระใน `personnel_assignments.position` ทำให้ตอบคำถาม
 * ของ SoT §13 ว่า "ใครมีอำนาจอะไร ณ เวลาใด" ได้ไม่แม่น เพราะชื่อที่ต่างกันแค่ช่องว่าง
 * นับเป็นคนละตำแหน่ง
 *
 * เป็นทะเบียนของ **ระบบกลาง** ไม่ใช่ของโมดูลสารบรรณ — โมดูลอื่นใช้ร่วมได้
 * และสารบรรณห้ามสร้างทะเบียนตำแหน่งของตัวเองซ้ำ (SoT §4)
 */
#[Fillable(['uuid', 'code', 'name', 'is_active'])]
class Position extends Model
{
    use HasPublicUuid;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function assignments()
    {
        return $this->hasMany(PersonnelAssignment::class);
    }
}
