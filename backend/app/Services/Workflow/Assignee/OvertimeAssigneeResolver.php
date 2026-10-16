<?php

namespace App\Services\Workflow\Assignee;

use App\Models\OrgUnitManager;
use App\Models\Overtime\OvertimeOrder;
use App\Models\Setting;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowStep;
use Illuminate\Support\Facades\DB;

/**
 * ผู้มีสิทธิ์อนุญาต/อนุมัติคำสั่ง OT ตามทะเบียนหัวหน้าหน่วยงาน (ACCESS-GOV-01 · B2)
 *
 * สายคำสั่ง OT (เจ้าของระบบกำหนด 2026-08-19): รองผู้อำนวยการฝ่ายบริหารทรัพยากรอนุญาต → ผู้อำนวยการอนุมัติ
 * เดิม workflow version ผูกขั้นกับบทบาท `Deputy Director` / `Director` ตามชื่อ — รองฯ ทุกคนอนุญาตได้
 *
 *   overtime_permit   → หัวหน้าของหน่วยงานที่ตั้งไว้ใน setting `overtime_permit_org_unit_id`
 *   overtime_approval → หัวหน้าของหน่วยระดับสถานศึกษา (ผู้อำนวยการ/รักษาการ)
 *
 * ยังไม่ได้ตั้งหน่วยงานหรือยังไม่มีหัวหน้า: คืน null ให้ engine ใช้บทบาทตามเดิม
 * เว้นแต่ปิดทางถอยไว้ (`workflow.approver_role_fallback = false`) จะคืนรายการว่าง = ไม่มีใครอนุมัติได้
 */
class OvertimeAssigneeResolver implements SubjectAssigneeResolver
{
    public const PERMIT_UNIT_SETTING = 'overtime_permit_org_unit_id';

    public function subjectType(): string
    {
        return (new OvertimeOrder)->getMorphClass();
    }

    public function candidates(WorkflowInstance $instance, WorkflowStep $step): ?array
    {
        $unitId = match ($step->code) {
            'overtime_permit' => (int) (Setting::where('key', self::PERMIT_UNIT_SETTING)->value('value') ?: 0) ?: null,
            'overtime_approval' => DB::table('org_units')->whereNull('parent_id')->orderBy('id')->value('id'),
            default => null,
        };

        $managers = $unitId === null ? [] : OrgUnitManager::query()
            ->where('org_unit_id', $unitId)
            ->inEffect()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($managers !== []) {
            return $managers;
        }

        return config('workflow.approver_role_fallback', true) ? null : [];
    }
}
