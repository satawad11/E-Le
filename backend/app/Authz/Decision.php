<?php

namespace App\Authz;

/**
 * ผลการตัดสินของ PermissionEngine::can() — allow/deny + เหตุผล + แหล่งสิทธิ์ที่ใช้ตัดสิน
 */
final class Decision
{
    public const ALLOWED = 'allowed';

    public const INACTIVE_ACCOUNT = 'inactive_account';

    public const UNKNOWN_PERMISSION = 'unknown_permission';

    public const MODULE_DISABLED = 'module_disabled';

    public const NO_PERMISSION = 'no_permission';

    public const OUT_OF_SCOPE = 'out_of_scope';

    public const RULE_DENIED = 'rule_denied';

    /** @param  array<int, array<string, mixed>>  $steps */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly string $message,
        public readonly ?Grant $grant = null,
        public readonly ?string $rule = null,
        public readonly array $steps = [],
    ) {}

    public function denied(): bool
    {
        return ! $this->allowed;
    }

    /** resource ที่อยู่นอก scope ต้องตอบ 404 เพื่อไม่บอกว่ามีอยู่ (IDOR) */
    public function hidesExistence(): bool
    {
        return $this->reason === self::OUT_OF_SCOPE;
    }
}
