<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonnelAssignment extends Model
{
    protected $fillable = ['uuid', 'user_id', 'org_unit_id', 'personnel_type_id', 'position', 'position_id', 'effective_from', 'effective_to'];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date'];

    /**
     * ตำแหน่งที่อ้างอิงได้ (INT-03)
     *
     * คอลัมน์ข้อความ `position` ยังคงอยู่และยังเป็นค่าที่โค้ดเดิมใช้ทั้งหมด
     * ตัวนี้เพิ่มเข้ามาเพื่อให้เทียบตำแหน่งด้วย id ได้ ไม่ต้องจับคู่ด้วยข้อความ
     */
    public function positionRef()
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orgUnit()
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function personnelType()
    {
        return $this->belongsTo(PersonnelType::class);
    }

    public function personnel()
    {
        return $this->belongsTo(Personnel::class, 'user_id', 'user_id');
    }
}
