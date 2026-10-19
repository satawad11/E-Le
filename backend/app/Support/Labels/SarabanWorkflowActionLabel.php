<?php

namespace App\Support\Labels;

/**
 * ป้ายภาษาไทยของ `saraban_document_workflow_steps.action` (REDESIGN-2569 · มาตรฐานภาษาไทย L4)
 */
class SarabanWorkflowActionLabel
{
    public const LABELS = [
        'submitted' => 'เสนอหนังสือ',
        'approved' => 'เห็นชอบ',
        'rejected' => 'ส่งกลับแก้ไข',
    ];

    public static function label(?string $action): string
    {
        return self::LABELS[$action] ?? 'การดำเนินการอื่น';
    }
}
