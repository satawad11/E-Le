<?php

namespace App\Models;

use App\Support\SystemRoles;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * SEC-026 — `system_key` คือตัวระบุบทบาทระบบ ไม่ใช่ `name`
 *
 * `name` เป็นชื่อที่แสดงและผู้ดูแลแก้ได้จากหน้าจอ จึงห้ามใช้เป็น identity
 * (`PROJECT_RULES.md` · Forbidden) · `system_key` ไม่เปิดให้แก้ผ่าน API ใด
 * จึงไม่อยู่ใน fillable
 */
#[Fillable(['name', 'description', 'guard_name'])]
class Role extends SpatieRole
{
    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /** บทบาทนี้เป็นบทบาทหลักของระบบหรือไม่ */
    public function isSystemRole(): bool
    {
        return (bool) $this->is_system || $this->system_key !== null;
    }

    /** บทบาทนี้คือผู้ดูแลระบบสูงสุดหรือไม่ */
    public function isSuperAdmin(): bool
    {
        return $this->system_key === SystemRoles::SUPER_ADMIN;
    }

    public function scopeWithSystemKey(Builder $query, string $key): Builder
    {
        return $query->where('system_key', $key);
    }

    /** บทบาทผู้ดูแลระบบสูงสุดตามคีย์ระบบ (ไม่ใช่ตามชื่อที่แสดง) */
    public static function superAdmin(): ?self
    {
        return static::query()->where('system_key', SystemRoles::SUPER_ADMIN)->first();
    }
}
