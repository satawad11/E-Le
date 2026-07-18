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
            'links.*.url' => 'required_with:links|url',
            'files' => 'nullable|array',
            'files.*' => 'file|max:20480', // max 20MB
        ];
    }
}
