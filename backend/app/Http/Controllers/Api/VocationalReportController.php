<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSource;
use App\Models\User;
use App\Services\Attendance\BiometricFileParserService;
use App\Services\Reporting\VocationalLeaveEvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VocationalReportController extends Controller
{
    public function __construct(
        private readonly VocationalLeaveEvaluationService $evaluationService,
        private readonly BiometricFileParserService $biometricParser,
    ) {}

    /**
     * Get Civil Service & Vocational Leave Evaluation Summary (Round 1 / Round 2 / Fiscal Year).
     */
    public function evaluationSummary(Request $request): JsonResponse
    {
        $request->validate([
            'cycle' => ['nullable', 'string', 'in:round_1,round_2,fiscal_year'],
            'year' => ['nullable', 'integer', 'min:2500', 'max:2650'],
            'org_unit_id' => ['nullable', 'integer', 'exists:org_units,id'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $cycle = (string) $request->input('cycle', 'round_1');
        $buddhistYear = $request->filled('year') ? (int) $request->input('year') : null;
        $orgUnitId = $request->filled('org_unit_id') ? (int) $request->input('org_unit_id') : null;

        $report = $this->evaluationService->getEvaluationSummary(
            viewer: $user,
            cycle: $cycle,
            buddhistYear: $buddhistYear,
            orgUnitId: $orgUnitId
        );

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }

    /**
     * Import raw scan log file (.DAT, .TXT, .CSV, .XLSX) from biometric devices.
     */
    public function importBiometricFile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->can('attendance.view_all') && ! $user->can('attendance.export') && ! $user->can('leave.management.manage') && ! $user->can('system.manage')) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่มีสิทธิ์ในการนำเข้าข้อมูลเวลาปฏิบัติราชการ',
            ], 403);
        }

        $request->validate([
            'file' => ['required_without:content', 'file', 'max:10240'], // 10MB max
            'content' => ['required_without:file', 'string'],
            'source_id' => ['nullable', 'integer', 'exists:attendance_sources,id'],
            'device_id' => ['nullable', 'integer'],
        ]);

        $content = '';
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $content = (string) file_get_contents($file->getRealPath());
        } else {
            $content = (string) $request->input('content');
        }

        $sourceId = $request->input('source_id');
        $source = $sourceId !== null
            ? AttendanceSource::find($sourceId)
            : AttendanceSource::query()->firstOrCreate(
                ['code' => 'BIOMETRIC-FILE-IMPORT'],
                ['name' => 'นำเข้าไฟล์เครื่องสแกน', 'type' => 'file_import', 'is_active' => true]
            );

        $events = $this->biometricParser->parseContent($content);

        if (empty($events)) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่พบรายการบันทึกเวลาที่ถูกต้องในไฟล์ หรือรูปแบบไฟล์ไม่ตรงกับมาตรฐาน',
                'data' => [
                    'total_events' => 0,
                    'imported' => 0,
                ],
            ], 422);
        }

        $result = $this->biometricParser->ingest(
            events: $events,
            source: $source,
            deviceId: $request->input('device_id') ? (int) $request->input('device_id') : null
        );

        return response()->json([
            'success' => true,
            'message' => "นำเข้าข้อมูลบันทึกเวลาสำเร็จ {$result['imported']} รายการ",
            'data' => $result,
        ]);
    }

    /**
     * Get Teacher PA / Personal Performance Evidence Pack.
     */
    public function teacherPaEvidence(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $request->validate([
            'target_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'year' => ['nullable', 'integer'],
        ]);

        $targetUserId = $request->filled('target_user_id') ? (int) $request->input('target_user_id') : $viewer->id;

        // Anti-IDOR check: If accessing another user's evidence, must have permission
        if ($targetUserId !== $viewer->id && ! $viewer->can('leave.report.view') && ! $viewer->can('leave.management.manage')) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่มีสิทธิ์เข้าถึงแฟ้มหลักฐานของบุคลากรท่านอื่น',
            ], 403);
        }

        $targetUser = User::with(['orgUnit:id,name', 'personnelType:id,name'])->findOrFail($targetUserId);

        $evidencePack = $this->evaluationService->getPersonalEvidence(
            user: $targetUser,
            buddhistYear: $request->input('year') ? (int) $request->input('year') : null
        );

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'department' => $targetUser->orgUnit?->name,
                    'personnel_type' => $targetUser->personnelType?->name,
                ],
                'cycle' => $evidencePack['cycle'],
                'evidence' => $evidencePack['evidence'],
            ],
        ]);
    }
}
