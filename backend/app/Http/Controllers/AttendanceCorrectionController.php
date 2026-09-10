<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Workflow\WorkflowTransition;
use App\Services\Attendance\AttendanceCorrectionService;
use App\Services\Attendance\AttendanceScopeService;
use Illuminate\Http\Request;

class AttendanceCorrectionController extends Controller
{
    public function __construct(
        private readonly AttendanceCorrectionService $corrections,
        private readonly AttendanceScopeService $scope,
    ) {}

    /**
     * คำขอแก้ไขเวลาของตนเอง
     *
     * CLEANUP-02 — ฟีเจอร์นี้มี endpoint สร้างและตัดสินใจมาตั้งแต่ LEGACY-08 แต่ไม่มี
     * endpoint สำหรับ "ดูรายการ" เลย หน้าจอจึงสร้างไม่ได้ และทั้งฟีเจอร์ไม่มีทางเข้า
     */
    public function mine(Request $request)
    {
        return response()->json(
            AttendanceCorrection::query()
                ->with(['attendance:id,date,check_in_time,check_out_time,status', 'user:id,name'])
                ->where('user_id', $request->user()->id)
                ->latest()
                ->paginate(50)
        );
    }

    /** คำขอแก้ไขเวลาที่ผู้ตรวจสอบคนนี้ต้องพิจารณา — จำกัดขอบเขตตามหน่วยงานที่มองเห็นได้ */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected,all'],
        ]);

        $query = AttendanceCorrection::query()
            ->with(['attendance:id,date,check_in_time,check_out_time,status', 'user:id,name,org_unit_id', 'user.orgUnit:id,name'])
            ->when(
                ($filters['status'] ?? 'pending') !== 'all',
                fn ($builder) => $builder->where('status', $filters['status'] ?? 'pending')
            )
            ->latest();

        return response()->json(
            $this->scope->applyToUserOwnedQuery($query, $request->user())->paginate(100)
        );
    }

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
