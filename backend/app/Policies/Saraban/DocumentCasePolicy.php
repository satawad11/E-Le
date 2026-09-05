<?php

namespace App\Policies\Saraban;

use App\Models\Saraban\DocumentCase;
use App\Models\User;
use App\Services\OrgScopeResolver;

/**
 * สิทธิ์การจัดการสารบบเรื่องรายเรื่อง (SoT §6, §22)
 *
 * **ที่มา:** การตรวจทานเมื่อ 2026-09-05 พบว่า `CaseController` ตรวจแค่ด่าน
 * `ability:` ระดับความสามารถ แต่ **ไม่ได้ตรวจสิทธิ์รายเรื่องเลย** — ผู้ที่สร้าง
 * หนังสือได้ในหน่วยงานตน จึงปิดสารบบเรื่องของหน่วยงานอื่นได้ และถอดหนังสือ
 * ออกจากสารบบที่ตนไม่เกี่ยวข้อง
 *
 * เป็นรูปแบบเดียวกับ IDOR ที่ `DocumentPolicy` ป้องกันไว้แล้วสำหรับตัวหนังสือ
 * — แต่ชั้นสารบบที่เพิ่มเข้ามาใน INT-18 ไม่ได้รับการป้องกันเดียวกัน
 *
 * ใช้ขอบเขตจาก `OrgScopeResolver` ตัวเดียวกับที่ระบบใช้ตัดสินทุกที่
 * สารบรรณไม่ได้สร้างกติกาขอบเขตของตัวเอง
 */
class DocumentCasePolicy
{
    public function __construct(private readonly OrgScopeResolver $scopes) {}

    public function viewAny(User $user): bool
    {
        return $user->can('saraban.document.view');
    }

    /**
     * เปิดดูสารบบได้กว้างกว่าการแก้ — รายการหนังสือข้างในถูกกรองด้วย
     * `DocumentPolicy` รายฉบับอยู่แล้ว (INT-18) จึงไม่รั่วแม้เปิดดูได้
     */
    public function view(User $user, DocumentCase $case): bool
    {
        return $user->can('saraban.document.view');
    }

    /**
     * แก้สารบบ — เพิ่ม/ถอดหนังสือ และปิดเรื่อง
     *
     * **เจ้าของเรื่องแก้ของตัวเองได้เสมอ** ส่วนคนอื่นต้องอยู่ในขอบเขตหน่วยงาน
     * ที่เป็นเจ้าของสารบบ · สารบบที่ไม่ได้ระบุหน่วยงานเจ้าของ (`null`) ถือเป็น
     * ของส่วนกลาง — ผู้ที่มีสิทธิ์สร้างหนังสือแก้ได้
     */
    public function update(User $user, DocumentCase $case): bool
    {
        if (! $user->can('saraban.document.create')) {
            return false;
        }

        if ((int) $case->created_by === (int) $user->id) {
            return true;
        }

        if ($case->owner_org_unit_id === null) {
            return true;
        }

        return $this->scopes->canSeeOrgUnit(
            $user,
            'saraban.document.create',
            $case->owner_org_unit_id,
            OrgScopeResolver::UNIT,
        );
    }
}
