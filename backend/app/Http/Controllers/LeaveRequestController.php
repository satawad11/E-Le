<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeaveAsAdminRequest;
use App\Http\Requests\StoreLeaveRequest;
use App\Http\Requests\UpdateLeaveRequest;
use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\LeaveType;
use App\Services\Attendance\LeaveAttendanceSync;
use App\Services\LeaveAuditService;
use App\Services\LeaveRequestNotificationService;
use App\Services\LeaveService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LeaveRequestController extends Controller
{
    public function __construct(
        private LeaveService $leaveService,
        private LeaveRequestNotificationService $notifications,
        private LeaveAttendanceSync $attendanceSync,
    ) {}

    public function store(StoreLeaveRequest $request)
    {
        $data = $request->validated();
        $leavePeriod = $data['leave_period'] ?? 'full_day';
        if ($leavePeriod !== 'full_day' && $data['start_date'] !== $data['end_date']) {
            return response()->json(['message' => 'การลาครึ่งวันต้องเลือกวันที่เริ่มต้นและสิ้นสุดเป็นวันเดียวกัน'], 422);
        }

        $leaveType = LeaveType::find($data['leave_type_id']);
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $today = Carbon::now()->startOfDay();

        if (! $leaveType->allow_past_dates && $start->lt($today)) {
            return response()->json(['message' => 'ประเภทการลานี้ไม่อนุญาตให้ลาล่วงหน้าย้อนหลังได้'], 422);
        }

        if ($leaveType->notice_period_days > 0) {
            $diffInDays = $today->diffInDays($start, false);
            if ($diffInDays < $leaveType->notice_period_days) {
                return response()->json(['message' => "ต้องยื่นใบลาล่วงหน้าอย่างน้อย {$leaveType->notice_period_days} วัน"], 422);
            }
        }

        /*
         * การตรวจใบลาซ้ำช่วงเวลาอยู่ที่ `LeaveService::createLeaveRequest()` ที่เดียว
         *
         * เดิมตรวจซ้ำอีกรอบตรงนี้ด้วยเงื่อนไขคนละชุด — ไม่ได้ยกเว้นสถานะ `Voided`
         * และไม่รู้จัก `Draft` ทำให้ร่างที่ยังไม่ได้ส่งไปกันช่วงเวลาของใบที่ยื่นจริง
         * อีกทั้งไม่ได้ล็อกแถว จึงกันการยื่นพร้อมกันสองครั้งไม่ได้อยู่แล้ว
         */
        $signaturePath = $request->user()->profile->signature_path ?? null;
        if ($request->filled('signature_base64')) {
            $base64Image = $request->string('signature_base64')->toString();
            if (preg_match('/^data:image\/(\w+);base64,/', $base64Image, $type)) {
                $base64Image = substr($base64Image, strpos($base64Image, ',') + 1);
                $type = strtolower($type[1]);
                if (in_array($type, ['jpg', 'jpeg', 'png'])) {
                    $fileName = 'signatures/'.uniqid().'.'.$type;
                    Storage::disk('private_uploads')->put($fileName, base64_decode($base64Image));
                    $signaturePath = '/storage/'.$fileName;
                }
            }
        }
        $data['signature_path'] = $signaturePath;

        /*
         * บันทึกเป็นร่างได้ — ร่างยังไม่ออกเลขที่หนังสือ ไม่กันโควตา และไม่แจ้งผู้อนุมัติ
         * ผู้ใช้จึงแก้หรือลบทิ้งได้อิสระโดยไม่ทิ้งรอยในทะเบียนหนังสือราชการ
         */
        $asDraft = $request->boolean('save_as_draft');

        $leave = $this->leaveService->createLeaveRequest($data, $request->user()->id, $request->file('attachment'), $asDraft);
        LeaveActionLog::create([
            'leave_id' => $leave->id,
            'user_id' => $request->user()->id,
            'action' => $asDraft ? 'Draft Saved' : 'Created',
            'details' => ['reason' => $data['reason']],
        ]);

        if ($asDraft) {
            return response()->json(['message' => 'บันทึกร่างใบลาเรียบร้อยแล้ว', 'leave' => $leave->load('type')], 201);
        }

        $this->notifications->notifyApprovers($leave);

        return response()->json(['message' => 'ส่งคำขอลาเรียบร้อยแล้ว', 'leave' => $leave->load('type')], 201);
    }

    /**
     * ส่งร่างเข้าสายอนุมัติ — จุดที่ใบลากลายเป็นเอกสารของทางราชการ
     */
    public function submit(Request $request, Leave $leave)
    {
        abort_unless($leave->user_id === $request->user()->id, 403, 'ส่งได้เฉพาะร่างใบลาของตนเอง');
        abort_unless($leave->status === 'Draft', 422, 'ใบลานี้ถูกส่งเข้าสายอนุมัติไปแล้ว');

        $leave = $this->leaveService->submitDraft($leave);

        LeaveActionLog::create([
            'leave_id' => $leave->id,
            'user_id' => $request->user()->id,
            'action' => 'Created',
            'details' => ['reason' => $leave->reason],
        ]);
        $this->notifications->notifyApprovers($leave);

        return response()->json(['message' => 'ส่งคำขอลาเรียบร้อยแล้ว', 'leave' => $leave->load('type')]);
    }

    public function storeAsAdmin(StoreLeaveAsAdminRequest $request)
    {
        $data = $request->validated();
        $data['contact_address'] = 'บันทึกโดยฝ่ายบุคคล';
        $data['signature_path'] = null;
        $leave = $this->leaveService->createLeaveRequest($data, $data['user_id'], null);
        $leave->status = 'Approved';
        $leave->save();
        LeaveActionLog::create([
            'leave_id' => $leave->id,
            'user_id' => $request->user()->id,
            'action' => 'Created & Approved',
            'details' => ['reason' => 'บันทึกวันลาแทนพนักงานโดยฝ่ายบุคคล: '.$data['reason']],
        ]);

        return response()->json(['message' => 'บันทึกวันลาเรียบร้อยแล้ว', 'leave' => $leave->load('type')], 201);
    }

    public function update(UpdateLeaveRequest $request, Leave $leave)
    {
        $user = $request->user();
        if (! $user->can('leave.management.manage')) {
            return response()->json(['message' => 'ไม่มีสิทธิ์แก้ไขใบลานี้'], 403);
        }
        $data = $request->validated();
        $leave->update($data);
        LeaveActionLog::create(['leave_id' => $leave->id, 'user_id' => $user->id, 'action' => 'Updated', 'details' => $data]);

        return response()->json(['message' => 'แก้ไขใบลาเรียบร้อยแล้ว', 'leave' => $leave->load('type')]);
    }

    /**
     * ลบได้เฉพาะ "ร่าง" เท่านั้น
     *
     * เดิมผู้ที่มีสิทธิ์ `leave.management.manage` ลบใบลาสถานะใดก็ได้ รวมทั้งที่อนุมัติแล้ว
     * และการลบยัง **ไม่เขียน audit log** เลยสักบรรทัด ประวัติการใช้วันลาของคนคนหนึ่ง
     * จึงถูกลบเกลี้ยงได้ด้วยคลิกเดียวโดยไม่เหลือร่องรอยว่าใครลบ เมื่อไร เพราะอะไร
     *
     * ระบบมีเครื่องมือที่ถูกต้องอยู่แล้วสำหรับใบที่เข้าสู่กระบวนการไปแล้ว:
     *   - ยังรออนุมัติ → "ยกเลิกใบลา" คืนวันที่กันไว้และเขียนประวัติ
     *   - อนุมัติแล้ว   → "ขอยกเลิก" (เดินสายอนุมัติ) หรือ "เพิกถอนเอกสาร" ที่บังคับใส่เหตุผล
     *
     * ทั้งสองทางเก็บใบลาไว้ในทะเบียนคุมการลาเสมอ จึงตรวจสอบย้อนหลังได้
     */
    public function destroy(Request $request, Leave $leave)
    {
        $user = $request->user();

        // ตอบเรื่องสิทธิ์ก่อนเสมอ ผู้ที่ไม่เกี่ยวข้องต้องไม่ได้รู้แม้แต่ว่าใบลานี้อยู่สถานะใด
        abort_unless(
            $leave->user_id === $user->id || $user->can('leave.management.manage'),
            403,
            'ไม่มีสิทธิ์ลบใบลานี้',
        );

        if ($leave->status !== 'Draft') {
            return response()->json([
                'message' => $leave->status === 'Pending'
                    ? 'ใบลาที่ยื่นแล้วลบไม่ได้ ให้ใช้ "ยกเลิกใบลา" แทน เพื่อให้ตรวจสอบย้อนหลังได้'
                    : 'ใบลาที่ผ่านการพิจารณาแล้วลบไม่ได้ ให้ใช้ "เพิกถอนเอกสาร" ซึ่งเก็บประวัติและเหตุผลไว้',
            ], 422);
        }

        $before = $leave->toArray();
        $leave->delete();

        LeaveActionLog::create([
            'leave_id' => $leave->id,
            'user_id' => $user->id,
            'action' => 'Draft Deleted',
            'details' => ['document_number' => $leave->document_number],
        ]);
        app(LeaveAuditService::class)->log($leave, 'draft_deleted', $before, null);

        return response()->json(['message' => 'ลบร่างใบลาเรียบร้อยแล้ว']);
    }
}
