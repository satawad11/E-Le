<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index()
    {
        $departments = Department::with('headOfDepartment.profile')->get();

        return response()->json($departments);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'head_of_department_id' => 'nullable|exists:users,id',
            'is_active' => 'boolean',
        ]);

        $department = Department::create($validated);
        $department->load('headOfDepartment');

        return response()->json([
            'message' => 'สร้างหน่วยงานเรียบร้อยแล้ว',
            'department' => $department,
        ], 201);
    }

    public function update(Request $request, Department $department)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'head_of_department_id' => 'nullable|exists:users,id',
            'is_active' => 'boolean',
        ]);

        $department->update($validated);
        $department->load('headOfDepartment');

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
