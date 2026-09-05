<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * โทเคนของ Sanctum ที่รู้จักคอลัมน์ที่ระบบนี้เพิ่มเข้าไป (SEC-GAP-01 · INT-11)
 *
 * `last_authenticated_at` ถูกเพิ่มโดย migration ของ INT-11 แต่โมเดลของ Sanctum
 * ไม่รู้จัก จึงคืนค่าเป็น **สตริง** ไม่ใช่ Carbon — ผู้เรียกทุกคนต้องจำไว้ว่าต้อง
 * แปลงเองก่อนใช้ ซึ่งเป็นกับดักที่จะทำให้โค้ดพังตอน runtime เมื่อมีคนลืม
 *
 * ประกาศ cast ไว้ที่นี่ที่เดียว แล้วทุกจุดในระบบได้ Carbon เหมือนคอลัมน์เวลาอื่น
 */
class AccessToken extends SanctumPersonalAccessToken
{
    /*
     * ต้องระบุตารางเอง — Eloquent เดาชื่อตารางจากชื่อคลาส ซึ่งจะได้ `access_tokens`
     * ไม่ใช่ `personal_access_tokens` ที่ Sanctum ใช้จริง
     */
    protected $table = 'personal_access_tokens';

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'last_authenticated_at' => 'datetime',
        ]);
    }
}
