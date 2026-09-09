<?php

namespace App\Http\Requests;

use App\Models\LeaveType;
use App\Rules\Base64Image;
use App\Support\UploadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreLeaveRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'leave_period' => 'nullable|in:full_day,morning,afternoon',
            'reason' => 'required|string|max:1000',
            'contact_address' => 'nullable|string|max:1000',
            // SEC-023R — allowlist กลางที่ `config/uploads.php` (เพดานของใบลาคือ 5 MB)
            'attachment' => array_merge(['nullable'], UploadRules::attachment(5120)),
            'substitute_id' => 'nullable|exists:users,id',
            'substitute_reason' => 'nullable|string|max:1000',
            'meta_data' => 'nullable|json',
            // SEC-028 — ตรวจจากเนื้อไฟล์จริงและจำกัดขนาดผ่าน allowlist กลาง
            'signature_base64' => ['nullable', 'string', new Base64Image('signatures')],
            // ช่องกรอกเฉพาะแบบฟอร์มของประเภทการลานั้น (ผู้ดูแลกำหนดเองได้)
            'extra_fields' => 'nullable|array',
            'extra_fields.*' => 'nullable|string|max:500',
        ];
    }

    /**
     * บังคับช่องเฉพาะแบบฟอร์มที่ผู้ดูแลตั้งเป็น "จำเป็น" ไว้
     *
     * นิยามช่องอยู่ใน `leave_types.extra_fields` จึงตรวจที่นี่แทนการ hard-code
     * ว่าแบบฟอร์มไหนต้องกรอกอะไร
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $type = LeaveType::find($this->input('leave_type_id'));
                $submitted = (array) $this->input('extra_fields', []);

                foreach ((array) ($type?->extra_fields ?? []) as $definition) {
                    $key = $definition['key'] ?? null;

                    if ($key === null) {
                        continue;
                    }

                    $label = $definition['label'] ?? $key;
                    $value = $submitted[$key] ?? null;
                    $required = filter_var($definition['required'] ?? false, FILTER_VALIDATE_BOOLEAN);

                    if ($required && blank($value)) {
                        $validator->errors()->add("extra_fields.{$key}", 'กรุณากรอก'.$label);

                        continue;
                    }

                    if (blank($value)) {
                        continue;
                    }

                    /*
                     * ตรวจตามชนิดที่ผู้ดูแลนิยามไว้จริง
                     *
                     * เดิมทุกช่องถูกตรวจเป็นข้อความล้วน ช่องวันที่จึงรับ "เมื่อวาน" ได้
                     * ช่องตัวเลขรับตัวอักษรได้ และช่องตัวเลือกรับค่านอกรายการได้
                     * ค่าที่ผิดชนิดจะไปโผล่บนแบบฟอร์มราชการที่พิมพ์ออกไปโดยไม่มีใครทัน
                     */
                    $this->validateExtraFieldType($validator, $definition, (string) $key, $label, $value);
                }
            },
        ];
    }

    /**
     * ตรวจค่าของช่องเฉพาะแบบฟอร์มตามชนิดที่นิยามไว้ใน `leave_types.extra_fields`
     *
     * ชนิดที่รองรับตรงกับที่ `Admin\LeaveTypeController` ยอมให้ตั้งได้เท่านั้น
     * (`text`, `textarea`, `date`, `number`, `select`) ชนิดอื่นถือเป็นข้อความล้วนตามเดิม
     *
     * @param  array<string, mixed>  $definition
     */
    private function validateExtraFieldType(Validator $validator, array $definition, string $key, string $label, mixed $value): void
    {
        $field = "extra_fields.{$key}";

        switch ($definition['type'] ?? 'text') {
            case 'date':
                if (! is_string($value) || strtotime($value) === false) {
                    $validator->errors()->add($field, $label.' ต้องเป็นวันที่ที่ถูกต้อง');
                }
                break;

            case 'number':
                if (! is_numeric($value)) {
                    $validator->errors()->add($field, $label.' ต้องเป็นตัวเลข');
                }
                break;

            case 'select':
                $options = array_map(
                    fn ($option) => trim((string) $option),
                    (array) ($definition['options'] ?? []),
                );

                if ($options !== [] && ! in_array(trim((string) $value), $options, true)) {
                    $validator->errors()->add($field, $label.' ต้องเลือกจากตัวเลือกที่กำหนดไว้');
                }
                break;
        }
    }
}
