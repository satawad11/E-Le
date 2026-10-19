<?php

namespace App\Services\Personnel;

use App\Contracts\Personnel\PersonnelFieldAccess;
use App\Models\Personnel;
use App\Models\PersonnelProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * แก้ข้อมูลบุคลากรตาม `editable_by` ของแต่ละฟิลด์ (CORE-02 · กฎกลาง hr_field_guard)
 *
 * | editable_by        | เจ้าของข้อมูล                  | งานบุคคล (canEditAsHr) |
 * |--------------------|-------------------------------|------------------------|
 * | self               | แก้ได้ทันที                     | แก้ได้                  |
 * | self_with_review   | ต้องยื่นคำขอ (Maker–Checker)    | แก้ได้                  |
 * | hr_only            | ไม่ได้                          | แก้ได้ **ยกเว้นข้อมูลของตนเอง** |
 *
 * ข้อมูลของตนเองที่เป็น hr_only แก้ไม่ได้แม้ผู้แก้มีสิทธิ์งานบุคคล (hr_field_guard)
 */
class PersonnelEditor
{
    public function __construct(
        private readonly PersonnelFieldAccess $access,
        private readonly FieldClassificationRegistry $classes,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  field => ค่าใหม่ (entity personnel หรือ personnel_profile)
     */
    public function update(User $actor, Personnel $personnel, string $entity, array $changes): void
    {
        $this->assertKnownEntity($entity);
        $isSelf = $actor->personnel_id !== null && $actor->personnel_id === $personnel->id;
        $asHr = ! $isSelf && $this->access->canEditAsHr($actor, $personnel);

        foreach (array_keys($changes) as $field) {
            $this->assertFieldExists($entity, $field);
            $rule = $this->classes->editableBy($entity, $field);

            if ($isSelf && $rule === 'hr_only') {
                throw new AuthorizationException("ฟิลด์ {$field} แก้ได้เฉพาะงานบุคคล และแก้ข้อมูลของตนเองไม่ได้");
            }
            if ($isSelf && $rule === 'self_with_review') {
                throw ValidationException::withMessages([$field => 'ฟิลด์นี้ต้องยื่นคำขอแก้ไขให้งานบุคคลตรวจ']);
            }
            if (! $isSelf && ! $asHr) {
                throw new AuthorizationException('ไม่มีสิทธิ์แก้ไขข้อมูลบุคลากรคนนี้');
            }
        }

        $this->apply($personnel, $entity, $changes, $actor->id, 'personnel.updated');
    }

    /**
     * บันทึกค่าโดยไม่ตรวจสิทธิ์ซ้ำ — ใช้โดย PersonnelChangeRequestService หลังผ่าน Maker–Checker แล้วเท่านั้น
     *
     * @param  array<string, mixed>  $changes
     */
    public function apply(Personnel $personnel, string $entity, array $changes, ?int $actorId, string $action): void
    {
        $this->assertKnownEntity($entity);

        DB::transaction(function () use ($personnel, $entity, $changes, $actorId, $action): void {
            $model = $entity === FieldClassificationRegistry::ENTITY_PERSONNEL
                ? $personnel
                : ($personnel->profile ?? new PersonnelProfile(['personnel_id' => $personnel->id]));

            $before = collect(array_keys($changes))->mapWithKeys(fn ($f) => [$f => $this->auditValue($entity, $f, $model->{$f})])->all();
            $model->forceFill($changes)->save();
            $after = collect(array_keys($changes))->mapWithKeys(fn ($f) => [$f => $this->auditValue($entity, $f, $model->{$f})])->all();

            $this->audit->log($action, $personnel, null, ['entity' => $entity] + $before, ['entity' => $entity] + $after, null, $actorId);
        });
    }

    /** ค่า sensitive ไม่ลง audit log เป็น plaintext */
    private function auditValue(string $entity, string $field, mixed $value): mixed
    {
        if ($this->classes->classOf($entity, $field) === 'sensitive') {
            return $value === null ? null : '[sensitive]';
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }

    private function assertKnownEntity(string $entity): void
    {
        if (! in_array($entity, [FieldClassificationRegistry::ENTITY_PERSONNEL, FieldClassificationRegistry::ENTITY_PROFILE], true)) {
            throw ValidationException::withMessages(['entity' => 'ไม่รู้จักหมวดข้อมูลนี้']);
        }
    }

    private function assertFieldExists(string $entity, string $field): void
    {
        if (! $this->classes->isClassified($entity, $field)) {
            throw ValidationException::withMessages([$field => "ไม่มีฟิลด์ {$field} ที่แก้ไขได้"]);
        }
    }
}
