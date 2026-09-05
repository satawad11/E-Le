<?php

namespace App\Services\Saraban;

use App\Models\Saraban\DistributionRecipient;
use App\Models\User;
use App\Services\Operations\FeatureFlags;
use App\Services\OrgScopeResolver;
use Illuminate\Support\Collection;

/**
 * แปลง "เป้าหมายที่ผู้ส่งเลือก" ให้เป็น "รายชื่อผู้รับจริง" (SoT §15)
 *
 * เป้าหมายที่รองรับ: รายบุคคล · หน่วยงานเดียว · หน่วยงานและหน่วยงานย่อยทั้งหมด ·
 * ผู้มีสิทธิ์เข้าถึงหนังสือทั้งหมด
 *
 * **ผู้ส่งส่งได้เฉพาะในขอบเขตที่ตนมองเห็น** — ขอบเขตมาจาก `OrgScopeResolver`
 * ซึ่งเป็นตัวตัดสินกลางของระบบ สารบรรณไม่ได้สร้างกติกาขอบเขตของตัวเอง
 * ถ้าไม่ตรวจข้อนี้ ผู้ที่มีสิทธิ์ส่งในหน่วยงานตนจะเวียนหนังสือถึงทั้งองค์กรได้
 *
 * **ไม่มีแนวคิด "กลุ่ม" แยกต่างหาก** — โครงสร้างหน่วยงานของระบบนี้เป็นต้นไม้
 * `org_subtree` จึงครอบคลุมทั้งฝ่าย แผนก และงานย่อย โดยไม่ต้องสร้างทะเบียนซ้ำ
 * และ **ห้ามใช้ `department_id`** ซึ่งมีหน่วยงานได้ระดับเดียว (INT-01 §1.4)
 */
class RecipientResolver
{
    public function __construct(
        private readonly OrgScopeResolver $scopes,
        private readonly FeatureFlags $flags,
    ) {}

    /**
     * @param  array<int,array{type:string,id?:int|null,role?:string}>  $targets
     * @return Collection<int,array{user:User,role:string,target_type:string,target_id:int|null}>
     *
     * @throws DistributionException
     */
    public function resolve(array $targets, User $sender): Collection
    {
        $visible = $this->scopes->visibleOrgUnitIds($sender, 'saraban.distribution.send', OrgScopeResolver::UNIT);

        /** @var Collection<int,array{user:User,role:string,target_type:string,target_id:int|null}> $resolved */
        $resolved = collect();

        foreach ($targets as $target) {
            $role = $target['role'] ?? DistributionRecipient::ROLE_TO;
            $users = $this->usersFor($target, $visible);

            foreach ($users as $user) {
                /*
                 * คนเดียวกันอาจเข้าเกณฑ์หลายเป้าหมาย เช่นถูกเลือกรายบุคคลด้วย
                 * และอยู่ในหน่วยงานที่เวียนถึงด้วย — เก็บครั้งแรกที่พบไว้
                 * และให้ `to` ชนะ `cc` เพราะผู้รับปฏิบัติมีหน้าที่มากกว่าผู้รับสำเนา
                 */
                $existing = $resolved->get($user->id);

                if ($existing !== null && ! ($existing['role'] === DistributionRecipient::ROLE_CC && $role === DistributionRecipient::ROLE_TO)) {
                    continue;
                }

                $resolved->put($user->id, [
                    'user' => $user,
                    'role' => $role,
                    'target_type' => $target['type'],
                    'target_id' => $target['id'] ?? null,
                ]);
            }
        }

        if ($resolved->isEmpty()) {
            throw new DistributionException('ไม่พบผู้รับที่ตรงกับเป้าหมายที่เลือก หรือเป้าหมายอยู่นอกขอบเขตที่ท่านส่งได้');
        }

        return $resolved->values();
    }

    /**
     * @param  array{type:string,id?:int|null}  $target
     * @param  array<int,int>|null  $visible  null = ไม่จำกัดขอบเขต
     * @return Collection<int,User>
     */
    private function usersFor(array $target, ?array $visible): Collection
    {
        $query = User::query()->with('currentAssignment.orgUnit', 'orgUnit');

        match ($target['type']) {
            DistributionRecipient::TARGET_USER => $query->whereKey($target['id']),

            DistributionRecipient::TARGET_ORG_UNIT => $query->where('org_unit_id', $target['id']),

            DistributionRecipient::TARGET_ORG_SUBTREE => $query->whereIn(
                'org_unit_id',
                $this->scopes->subtreeIds((int) $target['id']),
            ),

            /*
             * "ผู้มีสิทธิ์ทั้งหมด" = ผู้ที่เปิดดูหนังสือในระบบได้จริง
             * ไม่ใช่ "ผู้ใช้ทุกคน" — การเวียนถึงคนที่เปิดอ่านไม่ได้ ทำให้กล่อง
             * หนังสือเข้ามีรายการที่กดแล้วขึ้น 403 ซึ่งไม่ใช่การส่งหนังสือ
             */
            DistributionRecipient::TARGET_ALL_AUTHORIZED => ! $this->flags->enabled('saraban.distribution.all_authorized')
                ? throw new DistributionException('ระบบปิดการเวียนถึงผู้มีสิทธิ์ทั้งหมดไว้ กรุณาระบุหน่วยงานหรือรายบุคคล')
                : $query->where(
                    fn ($authorized) => $authorized
                        ->whereHas('permissions', fn ($permissions) => $permissions->where('name', 'saraban.document.view'))
                        ->orWhereHas('roles.permissions', fn ($permissions) => $permissions->where('name', 'saraban.document.view')),
                ),

            default => throw new DistributionException("ไม่รู้จักชนิดของเป้าหมาย: {$target['type']}"),
        };

        /*
         * ขอบเขตของผู้ส่ง — บังคับกับเป้าหมายทุกชนิดรวมถึงการเลือกรายบุคคล
         * เพราะการเลือกทีละคนไม่ใช่ข้ออ้างให้ข้ามขอบเขต
         */
        if ($visible !== null) {
            $query->whereIn('org_unit_id', $visible);
        }

        return $query->get();
    }
}
