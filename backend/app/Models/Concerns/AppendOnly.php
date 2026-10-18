<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * บันทึกแบบเพิ่มได้อย่างเดียว (PROJECT_RULES ข้อ 12)
 *
 * แถวที่บันทึกแล้วแก้หรือลบผ่าน Eloquent ไม่ได้ ตารางเหล่านี้ไม่มี `updated_at`
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' เป็นบันทึกแบบเพิ่มได้อย่างเดียว แก้ไขไม่ได้'));
        static::deleting(fn () => throw new LogicException(static::class.' เป็นบันทึกแบบเพิ่มได้อย่างเดียว ลบไม่ได้'));
    }

    public function usesTimestamps(): bool
    {
        return false;
    }

    public function initializeAppendOnly(): void
    {
        $this->mergeCasts(['created_at' => 'datetime']);
    }
}
