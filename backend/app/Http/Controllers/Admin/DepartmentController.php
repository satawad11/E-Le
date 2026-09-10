<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index()
    {
        $departments = Department::query()->get();

        return response()->json($departments);
    }

    /**
     * รายชื่อหน่วยงานสำหรับช่องตัวกรอง — ผู้ใช้ที่ล็อกอินแล้วเรียกได้ทุกคน
     *
     * CLEANUP-01 — เดิมมีสองทางที่พังทั้งคู่: หน้ารายงานการลาเรียก `/api/departments`
     * ที่ไม่เคยมี route อยู่จริง ส่วนหน้ารายงานการลงเวลาเรียก `/admin/departments-list`
     * ที่ route ชี้ไปยังเมธอดที่ไม่มีอยู่ ช่องตัวกรอง "หน่วยงาน" ทั้งสองหน้าจึงว่างเปล่าเสมอ
     *
     * รวมเหลือทางเดียวและคืนเฉพาะ id กับชื่อของหน่วยงานที่เปิดใช้งาน — เป็นโครงสร้าง
     * องค์กร ไม่ใช่ข้อมูลส่วนบุคคล จึงไม่ต้องใช้สิทธิ์จัดการผู้ใช้เพื่อเห็นรายชื่อ
     */
    public function options()
    {
        return response()->json(
            Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $department = Department::create($validated);

        return response()->json([
            'message' => 'สร้างหน่วยงานเรียบร้อยแล้ว',
            'department' => $department,
        ], 201);
    }

    public function update(Request $request, Department $department)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $department->update($validated);

        return response()->json([
            'message' => 'แก้ไขหน่วยงานเรียบร้อยแล้ว',
            'department' => $department,
        ]);
    }

    public function destroy(Department $department)
    {
        $department->delete();

        return response()->json(['message' => 'ลบหน่วยงานเรียบร้อยแล้ว']);
    }

    public function toggleStatus(Department $department)
    {
        $department->update(['is_active' => ! $department->is_active]);

        return response()->json([
            'message' => 'เปลี่ยนสถานะหน่วยงานเรียบร้อยแล้ว',
            'is_active' => $department->is_active,
        ]);
    }
}
