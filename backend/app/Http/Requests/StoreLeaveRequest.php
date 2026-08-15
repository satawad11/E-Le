<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
