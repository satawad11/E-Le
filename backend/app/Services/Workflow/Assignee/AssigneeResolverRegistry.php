<?php

namespace App\Services\Workflow\Assignee;

use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowStep;
use Illuminate\Contracts\Container\Container;

/**
 * ทะเบียนตัวแปลผู้มีสิทธิ์รายโมดูล
 *
 * มีเฉพาะตัวที่ประกาศไว้ในโค้ดเท่านั้น — เพิ่มโมดูลใหม่ = เพิ่มคลาสในรายการนี้
 */
class AssigneeResolverRegistry
{
    /** @var array<int,class-string<SubjectAssigneeResolver>> */
    private const RESOLVERS = [
        LeaveAssigneeResolver::class,
        OvertimeAssigneeResolver::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * ผู้มีสิทธิ์ทำขั้นนี้ตามกติกาของโมดูลเจ้าของเรื่อง — null = ไม่มีตัวแปลเฉพาะ
     *
     * @return array<int,int>|null
     */
    public function candidatesFor(WorkflowInstance $instance, ?WorkflowStep $step): ?array
    {
        if ($step === null) {
            return null;
        }

        foreach (self::RESOLVERS as $class) {
            /** @var SubjectAssigneeResolver $resolver */
            $resolver = $this->container->make($class);

            if ($resolver->subjectType() !== $instance->subject_type) {
                continue;
            }

            $candidates = $resolver->candidates($instance, $step);

            if ($candidates !== null) {
                return $candidates;
            }
        }

        return null;
    }
}
