<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ประเภทบุคลากร (CORE-02) — เป็นข้อมูล ห้าม hard-code ชื่อในตรรกะ
 */
class PersonnelType extends Model
{
    protected $fillable = ['code', 'name', 'description', 'has_license', 'has_contract_end', 'is_active'];

    protected $casts = ['has_license' => 'boolean', 'has_contract_end' => 'boolean', 'is_active' => 'boolean'];

    public function personnel(): HasMany
    {
        return $this->hasMany(Personnel::class);
    }

    public function policies(): HasMany
    {
        return $this->hasMany(LeavePolicy::class);
    }
}
