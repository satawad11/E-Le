<?php

namespace App\Authz;

/**
 * ขอบเขตที่รวมจากทุกแหล่งของ permission หนึ่ง — ใช้กรองรายการ (scopeQuery)
 *
 * เก็บเป็นรายการ "เงื่อนไขต่อแหล่ง" (terms) เพื่อให้ผลตรงกับ PermissionEngine::can() ทุกคู่:
 * รายการผ่านเมื่อเข้าเงื่อนไขของแหล่งใดแหล่งหนึ่ง **และ** ไม่อยู่ในหน่วยที่แหล่งนั้นถูกตัด (`excluded_paths`
 * จาก override deny ที่จำกัดหน่วย — ใช้กับทุก scope รวม `all` ยกเว้น `own`: เจ้าของข้อมูลเห็นข้อมูลของตนเองเสมอ)
 *
 * term: ['type' => all|own|units|subtree, 'unit_ids' => [], 'paths' => [], 'personnel_id' => ?int, 'excluded_paths' => []]
 */
final class AuthzScope
{
    /** @param  array<int, array{type: string, unit_ids: array<int, int>, paths: array<int, string>, personnel_id: ?int, excluded_paths: array<int, string>}>  $terms */
    public function __construct(public readonly array $terms = []) {}

    /** @param  array<int, Grant>  $grants */
    public static function fromGrants(array $grants, ?int $ownPersonnelId): self
    {
        $terms = [];
        foreach ($grants as $grant) {
            $type = match ($grant->scope) {
                'all' => 'all',
                'own' => 'own',
                'unit', 'assigned' => 'units',
                'subtree' => 'subtree',
            };
            if ($type === 'own' && $ownPersonnelId === null) {
                continue;
            }
            $term = [
                'type' => $type,
                'unit_ids' => $type === 'units' ? array_map('intval', array_keys($grant->anchors)) : [],
                'paths' => $type === 'subtree' ? array_values($grant->anchors) : [],
                'personnel_id' => $type === 'own' ? $ownPersonnelId : null,
                'excluded_paths' => $type === 'own' ? [] : array_values(array_unique($grant->excludedPaths)),
            ];
            if (($type === 'units' && $term['unit_ids'] === []) || ($type === 'subtree' && $term['paths'] === [])) {
                continue;
            }
            $terms[serialize($term)] = $term;
        }

        return new self(array_values($terms));
    }

    public function isEmpty(): bool
    {
        return $this->terms === [];
    }

    /** ไม่จำกัดและไม่มีหน่วยที่ถูกตัด — ผู้เรียกข้ามการกรองได้ทั้งหมด */
    public function isUnrestricted(): bool
    {
        foreach ($this->terms as $term) {
            if ($term['type'] === 'all' && $term['excluded_paths'] === []) {
                return true;
            }
        }

        return false;
    }
}
