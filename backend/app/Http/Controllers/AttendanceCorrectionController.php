<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Workflow\WorkflowTransition;
use App\Services\Attendance\AttendanceCorrectionService;
use Illuminate\Http\Request;

class AttendanceCorrectionController extends Controller
{
    public function __construct(private readonly AttendanceCorrectionService $corrections) {}

    public function store(Request $request, Attendance $attendance)
    {
        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1'],
            'changes.check_in_time' => ['sometimes', 'nullable', 'date'],
            'changes.check_out_time' => ['sometimes', 'nullable', 'date'],
            'changes.status' => ['sometimes', 'string', 'max:50'],
            'changes.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $correction = $this->corrections->request($attendance, $request->user(), $validated['changes'], $validated['reason']);

        return response()->json(['message' => 'ส่งคำขอแก้ไขข้อมูลลงเวลาแล้ว', 'correction' => $correction], 201);
    }

    public function approve(Request $request, AttendanceCorrection $correction)
    {
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $result = $this->corrections->decide($correction, $request->user(), WorkflowTransition::APPROVE, $validated['comment'] ?? null);

        return response()->json(['message' => 'อนุมัติและปรับปรุงข้อมูลลงเวลาแล้ว', 'correction' => $result]);
    }

    public function reject(Request $request, AttendanceCorrection $correction)
    {
        $validated = $request->validate(['comment' => ['required', 'string', 'max:2000']]);
        $result = $this->corrections->decide($correction, $request->user(), WorkflowTransition::REJECT, $validated['comment']);

        return response()->json(['message' => 'ไม่อนุมัติคำขอแก้ไขข้อมูลลงเวลา', 'correction' => $result]);
    }
}
