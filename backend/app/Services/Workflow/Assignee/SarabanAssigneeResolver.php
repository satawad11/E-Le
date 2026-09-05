<?php

namespace App\Services\Workflow\Assignee;

use App\Models\Saraban\Document;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowStep;
use App\Services\Saraban\AuthorityResolver;

/**
 * ผู้มีสิทธิ์ทำขั้นตอนของหนังสือ ในสายตาของ workflow engine (SoT §11, §13)
 *
 * ตรรกะกลางของ engine (`permissionCandidates()`) คืน **ทุกคนในระบบที่ถือสิทธิ์นั้น**
 * ซึ่งกว้างเกินไปสำหรับงานสารบรรณ — เจ้าหน้าที่สารบรรณของฝ่าย ก. จะกลายเป็น
 * ผู้มีสิทธิ์ตรวจหนังสือของฝ่าย ข. เพียงเพราะถือสิทธิ์ชื่อเดียวกัน
 *
 * ตัวแปลนี้จึงหรี่ให้เหลือเฉพาะผู้ที่ **มีอำนาจกับหน่วยงานเจ้าของหนังสือฉบับนั้นจริง**
 * โดยถาม `AuthorityResolver` ซึ่งเป็น projection จากข้อมูลของระบบกลาง
 * (สิทธิ์ + ผังหัวหน้าหน่วยงาน + ขอบเขต + การมอบอำนาจ)
 *
 * คืน `null` เมื่อขั้นนั้นไม่ได้ผูกกับสิทธิ์ — ปล่อยให้ engine ใช้ตรรกะกลางของตัวเอง
 * ตามสัญญาของ `SubjectAssigneeResolver`
 */
class SarabanAssigneeResolver implements SubjectAssigneeResolver
{
    public function __construct(private readonly AuthorityResolver $authority) {}

    public function subjectType(): string
    {
        return (new Document)->getMorphClass();
    }

    /**
     * @return array<int,int>|null
     */
    public function candidates(WorkflowInstance $instance, WorkflowStep $step): ?array
    {
        if ($step->assignee_type !== WorkflowStep::ASSIGNEE_PERMISSION || blank($step->assignee_permission)) {
            return null;
        }

        $document = Document::find($instance->subject_id);

        if ($document === null) {
            return null;
        }

        return $this->authority->holdersFor($step->assignee_permission, $document->owner_org_unit_id);
    }
}
