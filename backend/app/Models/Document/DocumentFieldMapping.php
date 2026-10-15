<?php

namespace App\Models\Document;

use App\Models\DocumentTemplateVersion;
use Illuminate\Database\Eloquent\Model;

/**
 * ตำแหน่งของฟิลด์บนแบบฟอร์ม PDF ของ "เวอร์ชันหนึ่ง ๆ"
 *
 * ผูกกับเวอร์ชันเสมอ การขยับตำแหน่งจึงไม่ย้อนไปเปลี่ยนเอกสารที่ออกไปแล้ว
 */
class DocumentFieldMapping extends Model
{
    protected $guarded = [];

    protected $casts = [
        'x' => 'float', 'y' => 'float', 'width' => 'float', 'height' => 'float',
        'font_size' => 'float', 'options' => 'array', 'is_active' => 'boolean',
        'page' => 'integer',
    ];

    public function version()
    {
        return $this->belongsTo(DocumentTemplateVersion::class, 'document_template_version_id');
    }
}
