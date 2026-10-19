<?php

namespace App\Authz;

/**
 * สิทธิ์หนึ่งคู่ (permission, scope) จากแหล่งเดียว — assignment, role grant หรือ override (CORE-04)
 *
 * `anchors` คือ [org_unit_id => path] ที่ scope unit/subtree/assigned ยึด · `excludedPaths` มาจาก override deny ที่จำกัดหน่วย
 */
final class Grant
{
    public const SOURCE_ASSIGNMENT = 'assignment';

    public const SOURCE_ROLE_GRANT = 'role_grant';

    public const SOURCE_OVERRIDE = 'override';

    /**
     * @param  array<int, string>  $anchors
     * @param  array<int, string>  $excludedPaths
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly string $permission,
        public readonly string $scope,
        public readonly string $sourceType,
        public readonly int $sourceId,
        public readonly string $label,
        public readonly array $anchors = [],
        public readonly ?string $template = null,
        public readonly array $attributes = [],
        public array $excludedPaths = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'permission' => $this->permission,
            'scope' => $this->scope,
            'source' => ['type' => $this->sourceType, 'id' => $this->sourceId, 'label' => $this->label, 'template' => $this->template],
            'anchors' => $this->anchors,
            'excluded_paths' => $this->excludedPaths,
            'attributes' => $this->attributes,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['permission'], $data['scope'], $data['source']['type'], $data['source']['id'], $data['source']['label'],
            $data['anchors'], $data['source']['template'], $data['attributes'], $data['excluded_paths'],
        );
    }
}
