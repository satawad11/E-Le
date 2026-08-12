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
            'message' => 'Department created successfully',
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
            'message' => 'Department updated successfully',
            'department' => $department,
        ]);
    }

    public function destroy(Department $department)
    {
        $department->delete();

        return response()->json(['message' => 'Department deleted successfully']);
    }

    public function toggleStatus(Department $department)
    {
        $department->update(['is_active' => ! $department->is_active]);

        return response()->json([
            'message' => 'Department status updated successfully',
            'is_active' => $department->is_active,
        ]);
    }
}
