<?php

namespace App\Models\Saraban;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ตัวนับเลขหนังสือของชุดเลขหนึ่งในปี พ.ศ. หนึ่ง (SoT §9)
 *
 * มีแถวเดียวต่อ (ชุดเลข, ปี) เพื่อให้ `lockForUpdate()` ล็อกได้ตรงจุด
 * โดยไม่ต้องใช้ `SELECT MAX()+1` ซึ่ง SoT ห้ามไว้
 *
 * การขึ้นปีใหม่คือการสร้างแถวใหม่ที่เริ่มจากศูนย์ — annual reset จึงเกิดขึ้นเอง
 */
#[Fillable(['number_series_id', 'buddhist_year', 'last_number'])]
class NumberSequence extends Model
{
    protected $table = 'saraban_number_sequences';

    public function series()
    {
        return $this->belongsTo(NumberSeries::class, 'number_series_id');
    }
}
