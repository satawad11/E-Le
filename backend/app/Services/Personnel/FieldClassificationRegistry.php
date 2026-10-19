<?php

namespace App\Services\Personnel;

use App\Models\FieldClassification;

/**
 * อ่าน `field_classifications` (CORE-02 / ADR-R-007)
 *
 * ฟิลด์ที่ไม่มีการจัดชั้นถือเป็น sensitive + hr_only (fail-closed) — ลืมจัดชั้นแล้วข้อมูลต้องไม่รั่ว
 */
class FieldClassificationRegistry
{
    public const ENTITY_PERSONNEL = 'personnel';

    public const ENTITY_PROFILE = 'personnel_profile';

    /** @var array<string, array<string, array{class: string, editable_by: string}>> */
    private array $cache = [];

    public function classOf(string $entity, string $field): string
    {
        return $this->entity($entity)[$field]['class'] ?? 'sensitive';
    }

    public function editableBy(string $entity, string $field): string
    {
        return $this->entity($entity)[$field]['editable_by'] ?? 'hr_only';
    }

    public function isClassified(string $entity, string $field): bool
    {
        return isset($this->entity($entity)[$field]);
    }

    /** @return array<string, array{class: string, editable_by: string}> */
    public function entity(string $entity): array
    {
        return $this->cache[$entity] ??= FieldClassification::where('entity', $entity)
            ->get(['field', 'class', 'editable_by'])
            ->mapWithKeys(fn (FieldClassification $f) => [$f->field => ['class' => $f->class, 'editable_by' => $f->editable_by]])
            ->all();
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
