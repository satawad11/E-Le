<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\LeaveEvidence;
use App\Services\PersonalDataAccessLogger;
use Illuminate\Http\Request;

/**
 * สิทธิของเจ้าของข้อมูลตาม ม.30 พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล
 * ให้บุคลากรขอรับสำเนาข้อมูลส่วนบุคคลของตนเองที่ระบบเก็บไว้ได้ด้วยตนเอง
 *
 * ให้ได้เฉพาะข้อมูลของผู้ที่ล็อกอินอยู่เท่านั้น ไม่มีพารามิเตอร์ให้ระบุคนอื่น
 */
class PersonalDataController extends Controller
{
    public function __construct(private PersonalDataAccessLogger $accessLog) {}

    /**
     * ประวัติว่าใครเปิดดูข้อมูลส่วนบุคคลของผู้ใช้ที่ล็อกอินอยู่บ้าง
     * ดูได้เฉพาะของตนเองเท่านั้น ไม่มีพารามิเตอร์ให้ระบุคนอื่น
     */
    public function accessHistory(Request $request)
    {
        return response()->json(
            $this->accessLog->historyQuery($request->user())->paginate(20)
        );
    }

    public function export(Request $request)
    {
        $user = $request->user()->load(['profile', 'department', 'personnelType', 'roles:id,name']);

        $leaveRecords = Leave::withTrashed()
            ->with('type:id,name')
            ->where('user_id', $user->id)
            ->orderBy('start_date')
            ->get();

        $leaves = $leaveRecords->map(fn (Leave $l) => [
            'document_number' => $l->document_number,
            'leave_type' => $l->type?->name,
            'start_date' => $l->start_date?->toDateString(),
            'end_date' => $l->end_date?->toDateString(),
            'total_days' => (float) $l->total_days,
            'reason' => $l->reason,
            'status' => $l->status,
            'submitted_at' => $l->submitted_at?->toIso8601String(),
        ]);

        $profile = $user->profile;

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'notice' => 'สำเนาข้อมูลส่วนบุคคลที่ระบบ E-Leave เก็บรักษาไว้ ตามมาตรา 30 พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562',
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'department' => $user->department?->name,
                'personnel_type' => $user->personnelType?->name,
                'roles' => $user->roles->pluck('name'),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'profile' => $profile ? [
                // เลขบัตรประชาชนเก็บแบบเข้ารหัส แสดงเฉพาะ 4 หลักท้ายเพื่อให้ยืนยันตัวได้โดยไม่เปิดเผยซ้ำ
                'id_card_number_last4' => $profile->id_card_number ? substr($profile->id_card_number, -4) : null,
                'title' => $profile->title,
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'date_of_birth' => $profile->date_of_birth?->toDateString(),
                'gender' => $profile->gender,
                'nationality' => $profile->nationality,
                'position' => $profile->position,
                'personnel_code' => $profile->personnel_code,
                'start_date' => $profile->start_date?->toDateString(),
                'education_level' => $profile->education_level,
                'degree' => $profile->degree,
                'address' => array_filter([
                    'house_number' => $profile->house_number, 'moo' => $profile->moo,
                    'soi' => $profile->soi, 'road' => $profile->road,
                    'sub_district' => $profile->sub_district, 'district' => $profile->district,
                    'province' => $profile->province, 'zipcode' => $profile->zipcode,
                ]),
            ] : null,
            'leaves' => $leaves,
            'attendance_records' => Attendance::where('user_id', $user->id)
                ->orderBy('date')
                ->get(['date', 'check_in_time', 'check_out_time', 'status', 'late_minutes'])
                ->map(fn ($a) => [
                    'date' => $a->date, 'check_in_time' => $a->check_in_time,
                    'check_out_time' => $a->check_out_time, 'status' => $a->status,
                    'late_minutes' => $a->late_minutes,
                ]),
            'leave_evidences' => LeaveEvidence::whereIn('leave_id', $leaveRecords->pluck('id'))
                ->get(['leave_id', 'evidence_type', 'original_name', 'created_at', 'purged_at'])
                ->map(fn ($e) => [
                    'leave_id' => $e->leave_id, 'evidence_type' => $e->evidence_type,
                    'file_name' => $e->original_name, 'uploaded_at' => $e->created_at?->toIso8601String(),
                    'file_purged_at' => $e->purged_at?->toIso8601String(),
                ]),
            // ผู้ที่เคยเปิดดูข้อมูลของเจ้าของข้อมูล เพื่อความโปร่งใสตาม ม.30
            'access_history' => $this->accessLog->historyFor($user),
        ];

        $filename = 'personal-data-'.$user->id.'-'.now()->format('Ymd').'.json';

        return response()->json($payload, 200, [
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
