<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveAsAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('leave.management.manage');
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|exists:users,id',
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|max:1000',
            'leave_period' => 'nullable|in:full_day,morning,afternoon',
            'contact_address' => 'nullable|string|max:1000',
            // ผู้ลงนามจริงของแต่ละขั้น step_order => user id (DELEGATION-01)
            'signers' => 'nullable|array',
            'signers.*' => 'nullable|integer|exists:users,id',
        ];
    }
}
