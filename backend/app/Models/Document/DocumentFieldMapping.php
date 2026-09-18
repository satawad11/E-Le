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
    /**
     * ชนิดฟิลด์ที่ตัวเรนเดอร์วาดได้จริง
     *
     * `DocumentFieldDefinition::TYPES` เป็นรายการชนิดของ "ตัวแปร" ซึ่งรวม BARCODE ไว้ตั้งแต่ต้น
     * แต่ `PdfOverlayRenderer` ไม่มีวิธีวาดบาร์โค้ด ฟิลด์ชนิดนี้จึงเคยถูกพิมพ์ออกมาเป็นข้อความ
     * ธรรมดาโดยไม่มีอะไรเตือน — หน้าออกแบบและ validation จึงยึดรายการนี้แทน
     * จนกว่าจะมีตัววาดบาร์โค้ดจริง (ดู docs/KNOWN-LIMITATIONS.md)
     */
    public const RENDERABLE_TYPES = ['TEXT', 'MULTILINE_TEXT', 'DATE', 'NUMBER', 'CHECKBOX', 'SIGNATURE', 'IMAGE', 'GARUDA', 'QR_CODE'];

    /** คีย์ที่อนุญาตใน `options` — กันค่าที่ไม่มีใครอ่านหลุดเข้ามาสะสมในฐานข้อมูล */
    public const OPTION_KEYS = ['mark', 'checked_values', 'underline', 'letter_spacing', 'rotate', 'visible_when'];

    /** ตัวดำเนินการของเงื่อนไขแสดง/ซ่อนฟิลด์ (whitelist — ไม่มีการรับนิพจน์อิสระจากหน้าจอ) */
    public const CONDITION_OPERATORS = ['equals', 'not_equals', 'filled', 'empty'];

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
