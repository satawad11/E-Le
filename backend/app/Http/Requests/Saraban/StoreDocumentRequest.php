<?php

namespace App\Http\Requests\Saraban;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorize via middleware/policy
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'type_id' => 'required|exists:saraban_document_types,id',
            'priority' => ['required', Rule::in(['normal', 'urgent', 'very_urgent', 'most_urgent'])],
            'secrecy' => ['required', Rule::in(['normal', 'secret', 'top_secret', 'most_secret'])],
            'department_id' => 'required|exists:departments,id',
            'document_number' => 'nullable|string|max:255',
            'document_date' => 'nullable|date',
            'incoming_document_number' => 'nullable|string|max:255',
            'incoming_date' => 'nullable|date',
            'to' => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:255',
            'attachments_note' => 'nullable|string',
            'remark' => 'nullable|string',
            'allow_download' => 'boolean',
            'creation_mode' => ['nullable', Rule::in(['physical', 'digital'])],
            'content' => 'nullable|string',
            'template_type' => 'nullable|string',
            'receivers' => 'nullable|array',
            'receivers.*' => 'exists:users,id',
            'receiver_departments' => 'nullable|array',
            'receiver_departments.*' => 'exists:departments,id',
            'links' => 'nullable|array',
            'links.*.title' => 'required_with:links|string|max:255',
            'links.*.url' => ['required_with:links', 'url:http,https'],
            'files' => 'nullable|array',
            /*
             * SEC-023 — ไฟล์แนบต้องผ่านทั้ง allowlist ของชนิดไฟล์จริงและนามสกุล
             *
             * `mimetypes:` ตัดสินจากเนื้อไฟล์ (magic bytes) ไม่ใช่ Content-Type
             * ที่ client ส่งมา ส่วน `extensions:` ตรวจนามสกุลจริงของไฟล์ที่อัปโหลด
             * กันไฟล์ที่เนื้อในดูปกติแต่ตั้งชื่อเป็น .php/.phtml/.html
             */
            'files.*' => [
                'file',
                'max:'.(int) config('saraban.attachments.max_size_kb', 20480),
                'mimetypes:'.implode(',', (array) config('saraban.attachments.allowed_mime_types', [])),
                'extensions:'.implode(',', (array) config('saraban.attachments.allowed_extensions', [])),
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'files.*.mimetypes' => 'ชนิดของไฟล์แนบนี้ไม่ได้รับอนุญาต (อนุญาต: :values)',
            'files.*.extensions' => 'นามสกุลของไฟล์แนบนี้ไม่ได้รับอนุญาต (อนุญาต: :values)',
            'files.*.max' => 'ไฟล์แนบต้องมีขนาดไม่เกิน :max กิโลไบต์',
        ];
    }
}
