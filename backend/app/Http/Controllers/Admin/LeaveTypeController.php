<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class LeaveTypeController extends Controller
{
    /**
     * การตั้งค่าประเภทการลาเป็นสิ่งที่กำหนดว่าใครลาอะไรได้และพิมพ์ด้วยแบบฟอร์มใด
     * จึงต้องตรวจย้อนได้ว่าใครเปลี่ยนอะไรเมื่อใด เช่นเดียวกับการตั้งค่าอื่นของระบบ
     */
    public function __construct(private readonly AuditLogger $audit) {}

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
            'attendance_bucket' => 'nullable|in:sick,personal,maternity,vacation,official,other',
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

        $this->audit->log('leave_type_created', 'LeaveType', $leaveType->id, after: $leaveType->toArray());

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
            'attendance_bucket' => 'nullable|in:sick,personal,maternity,vacation,official,other',
            'document_template_code' => 'nullable|string|max:100|exists:document_templates,code',
            'extra_fields' => 'nullable|array|max:20',
            'extra_fields.*.key' => 'required|string|max:50|alpha_dash',
            'extra_fields.*.label' => 'required|string|max:100',
            'extra_fields.*.type' => 'required|in:text,textarea,date,number,select',
            'extra_fields.*.required' => 'boolean',
            'extra_fields.*.options' => 'nullable|array|max:20',
            'extra_fields.*.options.*' => 'string|max:100',
        ]);

        $before = $type->toArray();
        $type->update($data);

        $this->audit->log('leave_type_updated', 'LeaveType', $type->id, before: $before, after: $type->fresh()->toArray());

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

        /*
         * นโยบายสิทธิและยอดวันลาผูกกับประเภทการลาแบบ cascade
         *
         * การลบประเภทการลาหนึ่งครั้งจึงลบ `leave_policies` และ `leave_balances` ของทุกคน
         * ที่ผูกกับประเภทนั้นไปด้วยเงียบ ๆ — รวมยอดยกมาและประวัติสิทธิที่ตรวจย้อนไม่ได้อีก
         * เดิมด่านเดียวคือ "มีใบลาหรือยัง" ซึ่งไม่ครอบกรณีที่ตั้งค่าสิทธิไว้แล้วแต่ยังไม่มีใครลา
         */
        $policies = $type->policies()->count();
        $balances = LeaveBalance::where('leave_type_id', $type->id)->count();

        if ($policies > 0 || $balances > 0) {
            return response()->json([
                'message' => sprintf(
                    'ลบไม่ได้เพราะมีนโยบายสิทธิ %d รายการและยอดวันลา %d รายการผูกกับประเภทนี้อยู่ — '
                    .'การลบจะทำให้สิทธิและยอดยกมาของบุคลากรหายไปด้วย '
                    .'ให้ปิดนโยบายที่หน้า "นโยบายสิทธิการลา" แทนการลบประเภทการลา',
                    $policies,
                    $balances,
                ),
            ], 422);
        }

        $before = $type->toArray();
        $type->delete();

        $this->audit->log('leave_type_deleted', 'LeaveType', $before['id'], before: $before);

        return response()->json(['message' => 'ลบประเภทการลาเรียบร้อยแล้ว']);
    }
}
