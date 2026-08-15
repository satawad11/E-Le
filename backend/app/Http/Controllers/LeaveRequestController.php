<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeaveAsAdminRequest;
use App\Http\Requests\StoreLeaveRequest;
use App\Http\Requests\UpdateLeaveRequest;
use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\LeaveType;
use App\Models\Setting;
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

        $overlapCount = Leave::where('user_id', $request->user()->id)
            ->whereNotIn('status', ['Cancelled', 'Rejected'])
            ->where(function ($query) use ($data) {
                $query->whereBetween('start_date', [$data['start_date'], $data['end_date']])
                    ->orWhereBetween('end_date', [$data['start_date'], $data['end_date']])
                    ->orWhere(function ($nested) use ($data) {
                        $nested->where('start_date', '<=', $data['start_date'])
                            ->where('end_date', '>=', $data['end_date']);
                    });
            })
            ->count();
        if ($overlapCount > 0) {
            return response()->json(['message' => 'คุณมีใบลาในช่วงเวลานี้อยู่แล้ว ไม่สามารถยื่นซ้ำได้'], 422);
        }

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
