<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['name', 'description', 'is_active'])]
class Department extends Model
{
    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::created(function (Department $department): void {
            $root = OrgUnit::where('type', 'organization')->whereNull('parent_id')->firstOrFail();
            $unit = OrgUnit::create([
                'uuid' => (string) Str::uuid(),
                'parent_id' => $root->id,
                'code' => 'DEPT-'.str_pad((string) $department->id, 6, '0', STR_PAD_LEFT),
                'name' => $department->name,
                'type' => 'department',
                'effective_from' => now()->toDateString(),
                'is_active' => $department->is_active ?? true,
            ]);
            $department->orgUnits()->attach($unit->id);
        });

        static::updated(function (Department $department): void {
            if ($department->wasChanged(['name', 'is_active'])) {
                $department->orgUnit?->update([
                    'name' => $department->name,
                    'is_active' => $department->is_active,
                ]);
            }
        });
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /*
     * ไม่มี `headOfDepartment` แล้ว — "ใครเป็นหัวหน้าหน่วยงานไหน" เป็นการมอบหมาย
     * ที่มีวันมีผลใน `org_unit_managers` ซึ่งเป็นสิ่งที่ตัวหาผู้อนุมัติอ่านจริง
     * ฟิลด์เดิม `head_of_department_id` ตั้งค่าได้แต่ไม่มีตรรกะใดอ่าน จึงถูกถอดออก
     * (ดู migration `2026_09_20_120000_move_department_heads_into_org_unit_managers`)
     */

    public function orgUnits()
    {
        return $this->belongsToMany(OrgUnit::class, 'department_org_unit_map')->withTimestamps();
    }

    public function getOrgUnitAttribute(): ?OrgUnit
    {
        return $this->orgUnits()->first();
    }
}
