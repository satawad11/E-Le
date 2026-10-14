<?php

namespace App\Models\Document;

use App\Models\DocumentTemplate;
use Illuminate\Database\Eloquent\Model;

/**
 * กติกาว่า entity ใดใช้แม่แบบใด
 *
 * รองรับหลาย entity ชี้แม่แบบเดียวกัน (เช่น ลาป่วย/ลากิจ/ลาคลอด ใช้แบบฟอร์มเดียวกัน)
 * และรองรับการเปลี่ยนแบบฟอร์มตามช่วงเวลาโดยไม่กระทบเอกสารที่ออกไปแล้ว
 */
class DocumentBindingRule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'conditions' => 'array',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function template()
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }
}
