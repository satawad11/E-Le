<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * ใครเห็นใบลาของใครได้บ้าง — จุดเดียวที่ตอบคำถามนี้ (LEGACY-04 · 04-4)
 *
 * เดิมคำตอบกระจายอยู่ตามคอนโทรลเลอร์ในรูปของรายชื่อ role ที่ hard-code ไว้
 * และรายชื่อนั้นก็ไม่ตรงกันสักที่ ที่แย่กว่านั้นคือชื่อ role หลายตัวที่โค้ดเช็ค
 * **ไม่มีอยู่จริงในฐานข้อมูล** เช่น `หัวหน้าแผนกวิชา / หัวหน้าฝ่าย`
 * ทำให้ตรรกะหรี่ขอบเขตไม่เคยทำงานเลย
 *
 * คลาสนี้ตอบด้วย permission ล้วน และหรี่ขอบเขตผ่าน OrgScopeResolver
 */
class LeaveVisibility
{
    /**
     * permission ที่ให้เห็นใบลาของผู้อื่น (ขอบเขตกว้างแค่ไหนแล้วแต่ scope)
     *
     * ชุดนี้ตรงกับเงื่อนไข `$canViewAll` เดิมใน LeaveController::index ทุกประการ
     * โดยตั้งใจ — ห้ามเพิ่มเข้าไปเองเพราะจะเป็นการให้สิทธิ์ใหม่
     *
     * หมายเหตุ: `leave.approval.hr` **ไม่ได้อยู่ในชุดนี้** เพราะโค้ดเดิมไม่ได้ให้
     * ผู้ถือสิทธิ์นั้นเห็นรายการใบลา (เขาเข้าถึงผ่านหน้า governance แยกต่างหาก)
     * มีเทสต์ล็อกไว้ว่าต้องคงเป็นแบบนี้ ถ้าจะเปลี่ยนต้องเป็นการตัดสินใจที่ชัดเจน
     *
     * ส่วนบทบาทที่โค้ดเดิมเช็คด้วยชื่อ (Director / Deputy Director / HR Head)
     * ปัจจุบันถือ `leave.report.view` และ `leave.approval.dept` อยู่แล้ว
     * จึงยังผ่านเงื่อนไขนี้เหมือนเดิม
     */
    private const CAN_SEE_OTHERS = [
        'leave.management.manage',
        'leave.report.view',
        'leave.approval.dept',
    ];

    /** permission ที่ใช้กำหนดขอบเขตหน่วยงานของการดูใบลา */
    public const SCOPE_ABILITY = 'leave.approval.dept';

    public function __construct(private readonly OrgScopeResolver $scopes) {}

    /** ผู้ใช้เห็นใบลาของคนอื่นได้หรือไม่ (ยังไม่คิดขอบเขต) */
    public function canSeeOthers(User $user): bool
    {
        foreach (self::CAN_SEE_OTHERS as $ability) {
            if ($user->can($ability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * จำกัด query ให้เหลือเฉพาะใบลาที่ผู้ใช้มองเห็นได้ตามขอบเขตหน่วยงาน
     *
     * ค่าเริ่มต้นคือ ORGANIZATION เพราะพฤติกรรมเดิมไม่เคยถูกจำกัดเลย
     * (role ที่ใช้หรี่ขอบเขตไม่มีอยู่จริง) การใช้ค่าอื่นจะเป็นการลดสิทธิ์
     * ของผู้ใช้ที่วันนี้เห็นทั้งองค์กรอยู่
     *
     * ผู้ดูแลหรี่ให้รายคนได้ด้วยการเพิ่มแถวใน `permission_scopes`
     *
     * @param  Builder<Leave>  $query
     */
    public function applyScope(Builder $query, User $user): Builder
    {
        $visible = $this->scopes->visibleOrgUnitIds(
            $user,
            self::SCOPE_ABILITY,
            OrgScopeResolver::ORGANIZATION,
        );

        if ($visible === null) {
            return $query;
        }

        if ($visible === []) {
            // ไม่เห็นหน่วยงานใดเลย — เหลือเฉพาะใบลาของตนเอง
            return $query->where('user_id', $user->id);
        }

        /*
         * เดิมมีทางถอยผ่าน `department_id` สำหรับข้อมูลที่ยังไม่ backfill · ผังองค์กร
         * เหลือชุดเดียวและผู้ใช้ทุกคนถูก backfill เข้า `org_unit_id` แล้ว ทางถอยจึงหมดหน้าที่
         * ผู้ใช้ที่ไม่มีหน่วยงานเลยจะไม่ปรากฏต่อผู้ที่มองเห็นเฉพาะบางหน่วย ซึ่งถูกต้อง
         * ตามหลัก fail-closed — และหน้าตรวจความพร้อมของสายอนุมัติชี้คนกลุ่มนี้ให้เห็นอยู่แล้ว
         */
        return $query->whereHas('user', fn (Builder $owner) => $owner->whereIn('org_unit_id', $visible));
    }
}
