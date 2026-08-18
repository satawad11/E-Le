<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * เติม `uuid` สาธารณะให้โมเดลอัตโนมัติตอนสร้างแถวใหม่
 *
 * ใช้กลไก `HasUniqueIds` ของ Eloquent ซึ่งทำงานใน `performInsert()`
 * **ก่อน** และ **ไม่ขึ้นกับ** model event
 *
 * เดิมผูกไว้กับ event `creating` ซึ่งพังเงียบ ๆ เมื่อผู้เรียกปิด event
 * เช่น `DatabaseSeeder` ที่ใช้ `WithoutModelEvents` — ทำให้ INSERT ตกด้วย
 * `SQLSTATE[HY000] 1364 Field 'uuid' doesn't have a default value`
 * และการติดตั้งใหม่ seed ไม่ผ่านบน MySQL
 *
 * พฤติกรรมเหมือนเดิมทุกอย่าง: เติมให้เฉพาะตอนที่ยังไม่มีค่า
 * ค่าที่ผู้เรียกกำหนดมาเองไม่ถูกทับ
 */
trait HasPublicUuid
{
    public function usesUniqueIds(): bool
    {
        return true;
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }
}
