<?php

namespace App\Http\Requests;

use App\Models\LeaveType;
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
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'substitute_id' => 'nullable|exists:users,id',
            'substitute_reason' => 'nullable|string|max:1000',
            'meta_data' => 'nullable|json',
            'signature_base64' => 'nullable|string',
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
                    $required = filter_var($definition['required'] ?? false, FILTER_VALIDATE_BOOLEAN);

                    if ($key !== null && $required && blank($submitted[$key] ?? null)) {
                        $validator->errors()->add(
                            "extra_fields.{$key}",
                            'กรุณากรอก'.($definition['label'] ?? $key)
                        );
                    }
                }
            },
        ];
    }
}
