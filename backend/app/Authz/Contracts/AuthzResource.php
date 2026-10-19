<?php

namespace App\Authz\Contracts;

/**
 * resource ที่ PermissionEngine ตรวจ scope และกฎกลางได้ (CORE-04)
 */
interface AuthzResource
{
    /** บุคลากรเจ้าของ resource (ใช้กับ scope `own` และกฎ `not_own`) */
    public function authzOwnerPersonnelId(): ?int;

    /**
     * หน่วยงานที่ resource สังกัด (ใช้กับ scope `unit` / `subtree` / `assigned`)
     *
     * @return array<int, int>
     */
    public function authzOrgUnitIds(): array;

    /** ผู้สร้าง/ผู้ยื่นรายการ (ใช้กับกฎ `maker_checker`) */
    public function authzMakerUserId(): ?int;
}
