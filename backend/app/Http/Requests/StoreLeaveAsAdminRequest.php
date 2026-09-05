<?php

namespace App\Http\Requests;

use App\Services\PermissionEvaluator;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveAsAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(PermissionEvaluator::class)->allows($this->user(), 'leave.management.manage');
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|exists:users,id',
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|max:1000',
        ];
    }
}
