<?php

namespace App\Services\Workflow\Assignee;

use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowStep;

/**
 * ผู้รู้ว่า "เรื่องชนิดนี้ ขั้นนี้ ใครมีสิทธิ์ทำ"
 *
 * workflow engine เป็นเครื่องจักรสถานะกลาง ไม่ควรรู้จักกติกาเฉพาะของโมดูลใด
 * โมดูลที่มีกติกาของตัวเอง (เช่น E-Leave ที่ผูกสิทธิ์กับ snapshot สายอนุมัติของใบลา)
 * จึงลงทะเบียนตัวแปลของตนผ่านสัญญานี้ แล้ว engine เรียกใช้โดยไม่ต้องรู้ว่าเป็นโมดูลอะไร
 */
interface SubjectAssigneeResolver
{
    /** ชนิดของเรื่องที่ตัวแปลนี้ดูแล (morph class) */
    public function subjectType(): string;

    /**
     * ผู้มีสิทธิ์ทำขั้นนี้ — คืน null เมื่อไม่มีข้อมูลเฉพาะ ให้ engine ใช้ตรรกะกลางของตัวเอง
     *
     * @return array<int,int>|null
     */
    public function candidates(WorkflowInstance $instance, WorkflowStep $step): ?array;
}
