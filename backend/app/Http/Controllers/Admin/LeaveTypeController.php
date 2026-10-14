<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use Illuminate\Http\Request;

class LeaveTypeController extends Controller
{
    /**
     * List all leave types.
     */
    public function index()
    {
        return response()->json(LeaveType::orderBy('name')->get());
    }

    /**
     * Create a new leave type.
     */
    public function store(Request $request)
    {

        $data = $request->validate([
            'name' => 'required|string|max:100|unique:leave_types,name',
            'code_prefix' => 'nullable|string|max:10|unique:leave_types,code_prefix',
            'max_days' => 'nullable|integer|min:1|max:3650',
            'description' => 'nullable|string|max:500',
            'requires_doc' => 'boolean',
            'is_carry_forward' => 'boolean',
            'max_carry_forward' => 'nullable|integer|min:1|max:365',
            'is_prorated' => 'boolean',
            'counts_toward_promotion' => 'boolean',
            'document_template_code' => 'nullable|string|max:100|exists:document_templates,code',
            'extra_fields' => 'nullable|array|max:20',
            'extra_fields.*.key' => 'required|string|max:50|alpha_dash',
            'extra_fields.*.label' => 'required|string|max:100',
            'extra_fields.*.type' => 'required|in:text,textarea,date,number,select',
            'extra_fields.*.required' => 'boolean',
            'extra_fields.*.options' => 'nullable|array|max:20',
            'extra_fields.*.options.*' => 'string|max:100',
        ]);

        $leaveType = LeaveType::create($data);

        return response()->json([
            'message' => 'เพิ่มประเภทการลาเรียบร้อยแล้ว',
            'leave_type' => $leaveType,
        ], 201);
    }

    /**
     * Update a leave type.
     */
    public function update(Request $request, LeaveType $type)
    {

        $data = $request->validate([
            'name' => "required|string|max:100|unique:leave_types,name,{$type->id}",
            'code_prefix' => "nullable|string|max:10|unique:leave_types,code_prefix,{$type->id}",
            'max_days' => 'nullable|integer|min:1|max:3650',
            'description' => 'nullable|string|max:500',
            'requires_doc' => 'boolean',
            'is_carry_forward' => 'boolean',
            'max_carry_forward' => 'nullable|integer|min:1|max:365',
            'is_prorated' => 'boolean',
            'counts_toward_promotion' => 'boolean',
            'document_template_code' => 'nullable|string|max:100|exists:document_templates,code',
            'extra_fields' => 'nullable|array|max:20',
            'extra_fields.*.key' => 'required|string|max:50|alpha_dash',
            'extra_fields.*.label' => 'required|string|max:100',
            'extra_fields.*.type' => 'required|in:text,textarea,date,number,select',
            'extra_fields.*.required' => 'boolean',
            'extra_fields.*.options' => 'nullable|array|max:20',
            'extra_fields.*.options.*' => 'string|max:100',
        ]);

        $type->update($data);

        return response()->json([
            'message' => 'แก้ไขประเภทการลาเรียบร้อยแล้ว',
            'leave_type' => $type,
        ]);
    }

    /**
     * Delete a leave type.
     */
    public function destroy(Request $request, LeaveType $type)
    {

        // Prevent deleting if there are leaves of this type
        if ($type->leaves()->exists()) {
            return response()->json([
                'message' => 'ไม่สามารถลบประเภทการลานี้ได้ เนื่องจากมีใบลาที่ใช้ประเภทนี้อยู่',
            ], 422);
        }

        $type->delete();

        return response()->json(['message' => 'ลบประเภทการลาเรียบร้อยแล้ว']);
    }
}
