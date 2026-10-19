<?php

namespace App\Authz;

/**
 * ขอบเขตที่รวมจากทุกแหล่งของ permission หนึ่ง — ใช้กรองรายการ (scopeQuery)
 *
 * รายการผ่านเมื่ออยู่ในขอบเขตของแหล่งใดแหล่งหนึ่ง: `all` · หน่วยใน `unitIds` · หน่วยใต้ `subtreePaths` · ของตนเอง (`ownPersonnelId`)
 */
final class AuthzScope
{
    /**
     * @param  array<int, int>  $unitIds
     * @param  array<int, string>  $subtreePaths
     */
    public function __construct(
        public readonly bool $all,
        public readonly array $unitIds,
        public readonly array $subtreePaths,
        public readonly ?int $ownPersonnelId,
    ) {}

    public function isEmpty(): bool
    {
        return ! $this->all && $this->unitIds === [] && $this->subtreePaths === [] && $this->ownPersonnelId === null;
    }
}
