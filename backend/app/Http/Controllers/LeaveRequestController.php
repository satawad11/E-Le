<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeaveAsAdminRequest;
use App\Http\Requests\StoreLeaveRequest;
use App\Http\Requests\UpdateLeaveRequest;
use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\LeaveApprovalSigner;
use App\Services\LeaveRequestNotificationService;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LeaveRequestController extends Controller
{
    public function __construct(
        private LeaveService $leaveService,
        private LeaveRequestNotificationService $notifications,
        private LeaveApprovalSigner $signer,
    ) {}

    public function store(StoreLeaveRequest $request)
    {
        $data = $request->validated();
        $leavePeriod = $data['leave_period'] ?? 'full_day';
        if ($leavePeriod !== 'full_day' && $data['start_date'] !== $data['end_date']) {
            return response()->json(['message' => 'การลาครึ่งวันต้องเลือกวันที่เริ่มต้นและสิ้นสุดเป็นวันเดียวกัน'], 422);
        }

        /*
         * กฎเรื่องเวลา (ห้ามลาย้อนหลัง / ต้องยื่นล่วงหน้า) และการซ้อนช่วงวัน ตรวจใน LeaveService
         * จาก leave_policies ซึ่งเป็นแหล่งเดียว — เดิมตรวจซ้ำที่นี่จาก leave_types อีกชุด
         * ทำให้นโยบายที่ผู้ดูแลตั้งผ่อนปรนไว้ไม่มีผล และใบลาที่ถูก void ยังกันการยื่นใหม่
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

        $leave = $this->leaveService->createLeaveRequest($data, $request->user()->id, $request->file('attachment'));
        LeaveActionLog::create([
            'leave_id' => $leave->id,
            'user_id' => $request->user()->id,
            'action' => 'Created',
            'details' => ['reason' => $data['reason']],
        ]);
        $this->notifications->notifyApprovers($leave);

        return response()->json(['message' => 'ส่งคำขอลาเรียบร้อยแล้ว', 'leave' => $leave->load('type')], 201);
    }

    public function storeAsAdmin(StoreLeaveAsAdminRequest $request)
    {
        $data = $request->validated();
        // ใบที่บันทึกแทนอนุมัติครบทุกขั้นทันที — ถ้าบันทึกให้ตนเองได้จะข้ามสายอนุมัติทั้งสาย
        abort_if((int) $data['user_id'] === $request->user()->id, 403, 'ห้ามบันทึกวันลาแทนให้ตนเอง กรุณายื่นใบลาตามปกติ');
        $data['contact_address'] = $data['contact_address'] ?? 'บันทึกโดยฝ่ายบุคคล';
        $data['signature_path'] = null;

        /*
         * DELEGATION-01 — ผู้ลงนามแต่ละขั้นคือผู้มีอำนาจตัวจริงที่ลงนามในเอกสารกระดาษ (หรือผู้ทำหน้าที่แทน)
         * ไม่ใช่เจ้าหน้าที่ที่บันทึก เดิมทุกขั้นพิมพ์ชื่อเจ้าหน้าที่เป็นผู้อนุมัติ
         * ตรวจทุกขั้นก่อนสร้างใบลา จะได้ไม่เหลือใบที่ค้างครึ่งทาง
         */
        $signatures = $this->recordedSignatures($request->user(), (int) $data['user_id'], $data['signers'] ?? []);

        $leave = DB::transaction(function () use ($data, $request, $signatures) {
            $leave = $this->leaveService->createLeaveRequest($data, (int) $data['user_id'], null, ['skip_timing_rules' => true]);

            return $this->leaveService->finalizeRecordedLeave($leave, $request->user(), $signatures, 'บันทึกวันลาแทนโดยฝ่ายบุคคล');
        });
        LeaveActionLog::create([
            'leave_id' => $leave->id,
            'user_id' => $request->user()->id,
            'action' => 'Created & Approved',
            'details' => ['reason' => 'บันทึกวันลาแทนพนักงานโดยฝ่ายบุคคล: '.$data['reason']],
        ]);

        return response()->json(['message' => 'บันทึกวันลาเรียบร้อยแล้ว', 'leave' => $leave->load('type')], 201);
    }

    /**
     * ผู้ที่เลือกเป็นผู้ลงนามได้ในแต่ละขั้น เมื่อฝ่ายบุคคลบันทึกวันลาแทนบุคลากรคนนี้
     */
    public function recordSigners(Request $request)
    {
        abort_unless($request->user()->can('leave.management.manage'), 403);
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $requester = User::findOrFail($data['user_id']);

        return response()->json(ApprovalStep::stepsFor(ApprovalStep::CHAIN_LEAVE)->map(fn (ApprovalStep $step) => [
            'step_order' => $step->step_order,
            'name' => $step->name,
            'candidates' => $this->signer->candidatesFor($requester, $step),
        ])->values());
    }

    /**
     * snapshot ผู้ลงนามของทุกขั้น ตามที่ฝ่ายบุคคลเลือก — abort 422 ถ้าขั้นใดไม่ได้เลือกหรือเลือกผู้ไม่มีอำนาจ
     *
     * @param  array<int|string, mixed>  $signers  step_order => user id
     * @return array<int, array<string, mixed>> step_order => คอลัมน์ snapshot
     */
    private function recordedSignatures(User $recorder, int $requesterId, array $signers): array
    {
        $requester = User::with('profile')->findOrFail($requesterId);
        $probe = (new Leave)->forceFill(['user_id' => $requester->id])->setRelation('user', $requester);
        $signatures = [];

        foreach (ApprovalStep::stepsFor(ApprovalStep::CHAIN_LEAVE) as $step) {
            $signerId = $signers[$step->step_order] ?? null;
            abort_if(blank($signerId), 422, 'กรุณาเลือกผู้ลงนามขั้น "'.$step->name.'" ตามเอกสารที่ลงนามแล้ว');

            $capacity = $this->signer->recordCapacity($recorder, $probe, $step, (int) $signerId);
            $signatures[$step->step_order] = $this->signer->snapshot($capacity, $recorder, null, null);
        }

        return $signatures;
    }

    public function update(UpdateLeaveRequest $request, Leave $leave)
    {
        $user = $request->user();
        if (! $user->can('leave.management.manage')) {
            return response()->json(['message' => 'ไม่มีสิทธิ์แก้ไขใบลานี้'], 403);
        }
        $data = $request->validated();
        $changesSchedule = (int) $data['leave_type_id'] !== (int) $leave->leave_type_id
            || $data['start_date'] !== $leave->start_date->toDateString()
            || $data['end_date'] !== $leave->end_date->toDateString()
            || (isset($data['leave_period']) && $data['leave_period'] !== ($leave->leave_period ?: 'full_day'));

        if ($changesSchedule) {
            // ใบที่อนุมัติ/ปิดแล้วห้ามแก้วันที่ตรง ๆ — ต้องขอยกเลิก (ทั้งหมดหรือบางส่วน) ตามระเบียบ
            if ($leave->status !== 'Pending') {
                return response()->json(['message' => 'แก้ไขวันที่หรือประเภทการลาได้เฉพาะใบลาที่รออนุมัติ ใบที่อนุมัติแล้วให้ใช้การขอยกเลิกวันลา'], 422);
            }
            $leave = $this->leaveService->reschedule($leave, $data);
        } else {
            $leave->update(['reason' => $data['reason']]);
        }
        LeaveActionLog::create(['leave_id' => $leave->id, 'user_id' => $user->id, 'action' => 'Updated', 'details' => $data]);

        return response()->json(['message' => 'แก้ไขใบลาเรียบร้อยแล้ว', 'leave' => $leave->load('type')]);
    }

    public function destroy(Request $request, Leave $leave)
    {
        $user = $request->user();
        $canDelete = $user->can('leave.management.manage');
        if (! $canDelete && $leave->user_id === $user->id && $leave->status === 'Pending') {
            $cancelDays = (int) (Setting::where('key', 'cancel_allowed_days')->value('value') ?? 5);
            $canDelete = $leave->created_at->diffInDays(now()) <= $cancelDays;
        }
        if (! $canDelete) {
            return response()->json(['message' => 'ไม่มีสิทธิ์ลบใบลานี้'], 403);
        }
        if (in_array($leave->status, ['Pending', 'Approved'], true)) {
            $this->leaveService->restoreBalance($leave);
        }
        $leave->delete();

        return response()->json(['message' => 'ลบใบลาเรียบร้อยแล้ว']);
    }
}
