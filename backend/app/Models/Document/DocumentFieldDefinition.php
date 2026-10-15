<?php

namespace App\Models\Document;

use Illuminate\Database\Eloquent\Model;

/**
 * Field Dictionary — ตัวแปรที่แบบฟอร์มและรายงานอ้างถึงได้
 *
 * ค่าเหล่านี้ถูกเติมโดย Data Provider เท่านั้น เทมเพลตไม่เคยแตะฐานข้อมูลเอง
 */
class DocumentFieldDefinition extends Model
{
    public const TYPES = ['TEXT', 'MULTILINE_TEXT', 'DATE', 'NUMBER', 'CHECKBOX', 'SIGNATURE', 'IMAGE', 'QR_CODE', 'BARCODE'];

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];
}
