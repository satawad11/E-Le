<?php

namespace App\Http\Requests\Saraban;

use App\Models\Saraban\Document;
use App\Support\UploadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * INT-05 — ข้อมูลที่ต้องมีเมื่อสร้างหนังสือ
 *
 * ค่าที่เป็นชุดตัวเลือก (ทิศทาง / ชั้นความลับ / ชั้นความเร็ว) ตรวจจากค่าคงที่
 * ในโมเดล ไม่ใช่จาก enum ของฐานข้อมูล — เพิ่มชั้นใหม่ได้โดยไม่ต้อง ALTER TABLE
 * ตามแนวทางที่ระบบเดิมใช้กับ `leaves.status` (RISK-13 ของ INT-03)
 *
 * ไฟล์แนบใช้กฎจาก allowlist กลาง จึงได้ด่านตรวจไวรัสของ INT-04 มาด้วยอัตโนมัติ
 */
class StoreDocumentRequest extends FormRequest
{
    /** สิทธิ์ถูกตัดสินที่ route (`ability:`) และ policy — ไม่ตัดสินซ้ำที่นี่ */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'document_type_id' => ['required', 'integer', Rule::exists('saraban_document_types', 'id')->where('is_active', true)],
            'direction' => ['required', 'string', Rule::in(Document::directions())],
            'subject' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'confidentiality' => ['required', 'string', Rule::in(Document::confidentialityLevels())],
            'urgency' => ['required', 'string', Rule::in(Document::urgencyLevels())],
            'owner_org_unit_id' => ['required', 'integer', Rule::exists('org_units', 'id')],
            'file' => array_merge(['nullable'], UploadRules::sarabanAttachment()),
        ];
    }

    /** @return array<string,string> */
    public function attributes(): array
    {
        return [
            'document_type_id' => 'ประเภทหนังสือ',
            'direction' => 'ทิศทางของหนังสือ',
            'subject' => 'เรื่อง',
            'summary' => 'สาระสำคัญ',
            'confidentiality' => 'ชั้นความลับ',
            'urgency' => 'ชั้นความเร็ว',
            'owner_org_unit_id' => 'หน่วยงานเจ้าของเรื่อง',
            'file' => 'ไฟล์หนังสือ',
        ];
    }
}
