<?php

namespace App\Http\Requests\Saraban;

use App\Models\Saraban\Document;
use App\Support\UploadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * INT-07 — ข้อมูลที่ต้องมีเมื่อลงทะเบียนรับหนังสือ (SoT §10)
 *
 * `source_*` คือข้อมูลของหนังสือต้นทางที่ผู้ส่งออกมา — เป็น nullable เพราะ
 * หนังสือบางฉบับ (เช่น หนังสือจากบุคคลทั่วไป) ไม่มีเลขที่และวันที่ตามระเบียบ
 * ส่วน `sender_name` บังคับเสมอ เพราะทะเบียนรับที่ไม่รู้ว่ารับจากใครใช้ไม่ได้
 *
 * ไม่รับ `received_at` จากผู้ใช้โดยตั้งใจ — วันเวลาที่รับต้องมาจากเวลาอ้างอิง
 * ของระบบ (SoT §10) ไม่ใช่ค่าที่ผู้กรอกพิมพ์เองได้
 */
class RegisterIncomingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'document_type_id' => ['required', 'integer', Rule::exists('saraban_document_types', 'id')->where('is_active', true)],
            'subject' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'confidentiality' => ['required', 'string', Rule::in(Document::confidentialityLevels())],
            'urgency' => ['required', 'string', Rule::in(Document::urgencyLevels())],
            'owner_org_unit_id' => ['required', 'integer', Rule::exists('org_units', 'id')],

            'sender_name' => ['required', 'string', 'max:255'],
            'source_number' => ['nullable', 'string', 'max:120'],
            'source_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],

            // ยืนยันว่าตรวจหนังสือซ้ำแล้วและยืนยันจะรับซ้ำ
            'acknowledge_duplicate' => ['nullable', 'boolean'],

            'file' => array_merge(['nullable'], UploadRules::sarabanAttachment()),
        ];
    }

    /** @return array<string,string> */
    public function attributes(): array
    {
        return [
            'document_type_id' => 'ประเภทหนังสือ',
            'subject' => 'เรื่อง',
            'confidentiality' => 'ชั้นความลับ',
            'urgency' => 'ชั้นความเร็ว',
            'owner_org_unit_id' => 'หน่วยงานเจ้าของเรื่อง',
            'sender_name' => 'จาก (ผู้ส่ง)',
            'source_number' => 'เลขที่หนังสือต้นทาง',
            'source_date' => 'ลงวันที่',
            'file' => 'ไฟล์หนังสือ',
        ];
    }
}
