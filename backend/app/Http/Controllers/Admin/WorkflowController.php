<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalStep;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkflowController extends Controller
{
    public function index(Request $request)
    {

        $steps = ApprovalStep::with('role')->orderBy('step_order', 'asc')->get();

        return response()->json($steps);
    }

    public function store(Request $request)
    {

        $request->validate([
            'steps' => 'required|array',
            'steps.*.role_id' => 'required|exists:roles,id',
            'steps.*.name' => 'required|string|max:255',
        ]);

        $steps = DB::transaction(function () use ($request) {
            // Delete all existing steps
            ApprovalStep::query()->delete();

            $savedSteps = [];
            foreach ($request->steps as $index => $stepData) {
                $savedSteps[] = ApprovalStep::create([
                    'role_id' => $stepData['role_id'],
                    'name' => $stepData['name'],
                    'step_order' => $index + 1,
                ]);
            }

            return $savedSteps;
        });

        return response()->json([
            'message' => 'บันทึกลำดับการอนุมัติเรียบร้อยแล้ว',
            'steps' => ApprovalStep::with('role')->orderBy('step_order', 'asc')->get(),
        ]);
    }
}
