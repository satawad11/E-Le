<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use App\Models\OrgUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ชุดเลขหนังสือ (SoT §9)
 *
 * แยกตามประเภทหนังสือและหน่วยงาน — `org_unit_id` เป็น null หมายถึงชุดเลข
 * ระดับองค์กรที่ทุกหน่วยงานใช้ร่วมกัน
 */
#[Fillable(['uuid', 'code', 'name', 'category', 'org_unit_id', 'prefix', 'is_active'])]
class NumberSeries extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_number_series';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function orgUnit()
    {
        return $this->belongsTo(OrgUnit::class, 'org_unit_id');
    }

    public function numbers()
    {
        return $this->hasMany(DocumentNumber::class, 'number_series_id');
    }
}
