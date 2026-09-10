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

        return $query->whereHas('user', function (Builder $owner) use ($visible): void {
            $owner->whereIn('org_unit_id', $visible)
                /*
                 * ทางถอยผ่านแผนกเดิมใช้ได้เฉพาะกับผู้ใช้ที่ **ยังไม่มี** org_unit_id เท่านั้น
                 *
                 * เดิมเงื่อนไขนี้เป็น `orWhereIn('department_id', ...)` เฉย ๆ ผู้ใช้ที่มี
                 * org_unit_id อยู่นอกขอบเขตที่มองเห็นได้ แต่บังเอิญแผนกเดิมของเขา map
                 * เข้ามาในขอบเขตนั้น จะโผล่ให้เห็นด้วย — เป็นการขยายสิทธิ์การมองเห็น
                 * โดยไม่ตั้งใจเมื่อผังสองชุดไม่ตรงกัน ซึ่งเป็นเหตุผลเดียวกับที่ต้องเหลือผังชุดเดียว
                 */
                ->orWhere(function (Builder $legacy) use ($visible): void {
                    $legacy->whereNull('org_unit_id')
                        ->whereIn('department_id', function ($sub) use ($visible): void {
                            $sub->select('department_id')
                                ->from('department_org_unit_map')
                                ->whereIn('org_unit_id', $visible);
                        });
                });
        });
    }
}
