<?php

namespace App\Services\Saraban;

use App\Models\OrgUnit;
use App\Models\Saraban\DocumentType;
use App\Models\User;
use App\Models\Workflow\WorkflowDefinition;
use App\Models\Workflow\WorkflowStep;
use App\Models\Workflow\WorkflowVersion;
use App\Services\TrustedClock;

/**
 * จำลองเส้นทางเดินหนังสือ (SoT §12 · INT-09)
 *
 * ข้อบังคับของ SoT §12 ที่คลาสนี้มีไว้เพื่อรักษา:
 *
 *   1. **READ ONLY** — ห้ามสร้าง workflow instance, ห้ามจองหรือออกเลข,
 *      ห้ามส่งการแจ้งเตือน, ห้ามแก้สถานะเอกสารใด ๆ
 *   2. **ใช้ resolver ตัวเดียวกับของจริง** — ถ้าจำลองด้วยตรรกะคนละชุด ผลที่ได้
 *      จะเป็นคำตอบของระบบที่ไม่มีอยู่จริง ซึ่งอันตรายกว่าไม่มีการจำลองเลย
 *   3. **บอกเหตุเมื่อหาผู้มีอำนาจไม่ได้** (`UNRESOLVED_AUTHORITY`) เพื่อให้ผู้ดูแล
 *      รู้ล่วงหน้าว่าหนังสือจะไปค้างที่ขั้นใด ก่อนที่จะมีหนังสือจริงไปค้าง
 *
 * ตัวจำลองนี้ **ไม่แตะฐานข้อมูลในเชิงเขียนเลย** — อ่านนิยามสายงานกับข้อมูลอำนาจ
 * แล้วคำนวณในหน่วยความจำ จึงเรียกกี่ครั้งก็ไม่ทิ้งร่องรอย
 */
class WorkflowSimulationService
{
    public const STATUS_VALID = 'VALID';

    public const STATUS_VALID_WITH_WARNINGS = 'VALID_WITH_WARNINGS';

    public const STATUS_UNRESOLVED_AUTHORITY = 'UNRESOLVED_AUTHORITY';

    public const STATUS_INVALID = 'INVALID';

    public function __construct(
        private readonly AuthorityResolver $authority,
        private readonly TrustedClock $clock,
    ) {}

    /**
     * จำลองเส้นทางของหนังสือสมมติหนึ่งฉบับ
     *
     * @param  array{document_type_id?:int|null, org_unit_id:int, as_of?:string|null, workflow_version_id?:int|null}  $input
     * @return array<string,mixed>
     */
    public function simulate(array $input): array
    {
        $asOf = $input['as_of'] ?? $this->clock->today();
        $orgUnit = OrgUnit::find($input['org_unit_id']);

        if ($orgUnit === null) {
            return $this->invalid('ไม่พบหน่วยงานที่ระบุ', $asOf);
        }

        $version = $this->resolveVersion($input['workflow_version_id'] ?? null);

        if ($version === null) {
            return $this->invalid('ยังไม่มีสายการเดินหนังสือที่มีผลบังคับใช้', $asOf);
        }

        $documentType = isset($input['document_type_id'])
            ? DocumentType::find($input['document_type_id'])
            : null;

        $steps = [];
        $warnings = [];
        $unresolved = false;

        foreach ($version->steps()->orderBy('step_order')->get() as $step) {
            $resolved = $this->resolveStep($step, $orgUnit->id, $asOf);

            if ($resolved['candidates'] === []) {
                $unresolved = true;
                $warnings[] = "ขั้น \"{$step->name}\" ยังไม่มีผู้มีอำนาจในหน่วยงานนี้";
            }

            $steps[] = $resolved;
        }

        return [
            'status' => $unresolved ? self::STATUS_UNRESOLVED_AUTHORITY
                : ($warnings === [] ? self::STATUS_VALID : self::STATUS_VALID_WITH_WARNINGS),
            'as_of' => $asOf,
            'workflow' => [
                'definition_code' => $version->definition->code,
                'version' => $version->version,
                'version_status' => $version->status,
            ],
            'context' => [
                'org_unit' => ['id' => $orgUnit->id, 'name' => $orgUnit->name],
                'document_type' => $documentType === null ? null : ['id' => $documentType->id, 'name' => $documentType->name],
            ],
            'route' => $steps,
            'warnings' => $warnings,
        ];
    }

    /**
     * ผู้มีอำนาจของขั้นหนึ่ง ณ วันที่กำหนด
     *
     * ใช้ `AuthorityResolver` ตัวเดียวกับที่ `SarabanAssigneeResolver` ใช้ตอนเดินจริง
     * ผลของการจำลองกับผลจริงจึงตรงกันเสมอโดยไม่ต้องดูแลตรรกะสองชุด (SoT §12)
     *
     * @return array<string,mixed>
     */
    private function resolveStep(WorkflowStep $step, int $orgUnitId, string $asOf): array
    {
        $ability = $step->assignee_permission;

        $candidates = $ability !== null && $step->assignee_type === WorkflowStep::ASSIGNEE_PERMISSION
            ? $this->authority->holdersFor($ability, $orgUnitId, $asOf)
            : [];

        return [
            'step_code' => $step->code,
            'step_name' => $step->name,
            'order' => $step->step_order,
            'assignee_type' => $step->assignee_type,
            'required_ability' => $ability,
            'is_final' => (bool) $step->is_final,
            'candidates' => $this->describeCandidates($candidates),
            'unresolved_reason' => $candidates === []
                ? ($ability === null
                    ? 'ขั้นนี้ไม่ได้ผูกกับสิทธิ์ จึงจำลองผู้มีอำนาจไม่ได้'
                    : "ไม่มีผู้ใดถือสิทธิ์ {$ability} ที่มีอำนาจกับหน่วยงานนี้")
                : null,
        ];
    }

    /**
     * @param  array<int,int>  $userIds
     * @return array<int,array{id:int,name:string}>
     */
    private function describeCandidates(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => (int) $user->id, 'name' => $user->name])
            ->all();
    }

    /**
     * เวอร์ชันที่จะใช้จำลอง
     *
     * ระบุเวอร์ชันได้เพื่อดูว่า "ถ้าเผยแพร่ร่างนี้แล้วเส้นทางจะเป็นอย่างไร"
     * ก่อนที่จะเผยแพร่จริง — ซึ่งเป็นประโยชน์หลักของการจำลองตาม SoT §12
     */
    private function resolveVersion(?int $versionId): ?WorkflowVersion
    {
        if ($versionId !== null) {
            return WorkflowVersion::with('definition')->find($versionId);
        }

        $definition = WorkflowDefinition::query()
            ->where('code', DocumentWorkflowService::DEFINITION_CODE)
            ->first();

        return $definition?->activeVersion()?->load('definition');
    }

    /** @return array<string,mixed> */
    private function invalid(string $reason, string $asOf): array
    {
        return [
            'status' => self::STATUS_INVALID,
            'as_of' => $asOf,
            'workflow' => null,
            'context' => null,
            'route' => [],
            'warnings' => [$reason],
        ];
    }
}
