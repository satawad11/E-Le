<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ชั้นความลับของฟิลด์ (CORE-02 / ADR-R-007): public · internal · confidential · sensitive
 */
class FieldClassification extends Model
{
    public const CLASSES = ['public', 'internal', 'confidential', 'sensitive'];

    public const EDITABLE_BY = ['self', 'self_with_review', 'hr_only'];

    protected $fillable = ['entity', 'field', 'class', 'editable_by'];
}
