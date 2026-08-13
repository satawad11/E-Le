<?php

namespace App\Http\Controllers;

use App\Models\ApprovalStep;
use App\Models\FiscalYear;
use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\LeaveRequested;
use App\Services\Export\LeaveWordService;
use App\Services\LeavePolicyService;
use App\Services\LeaveService;
use App\Services\LeaveVerificationService;
use App\Services\LeaveVisibility;
use App\Services\TelegramNotificationService;
use App\Services\ThaiDateService;
use App\Support\StoredFile;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\PhpWord;

class LeaveController extends Controller
{
    protected $leaveService;

    protected LeavePolicyService $policies;

    public function __construct(LeaveService $leaveService, LeavePolicyService $policies)
    {
        $this->leaveService = $leaveService;
        $this->policies = $policies;
    }

    public function index(Request $request, LeaveVisibility $visibility)
    {
        $user = $request->user();

        // LEGACY-04 (04-4): เดิมตัดสินจากรายชื่อ role ที่ hard-code ไว้
        // ซึ่งหลายชื่อไม่มีอยู่จริงในฐานข้อมูล ตอนนี้ถามจาก permission ล้วน
        $canViewAll = $visibility->canSeeOthers($user);

        $perPage = min((int) ($request->input('per_page', 100)), 500);

        if ($canViewAll) {
            $query = Leave::with(['user:id,name,department_id', 'user.department', 'type:id,name', 'approvals.approver:id,name', 'actionLogs.user:id,name'])
                ->orderBy('created_at', 'desc');

            // LEGACY-04 (04-4): เดิมหรี่ขอบเขตด้วยชื่อ role `หัวหน้าแผนกวิชา / หัวหน้าฝ่าย`
            // ซึ่งไม่มีอยู่จริง ตรรกะจึงไม่เคยทำงาน — ตอนนี้ใช้ permission_scopes
            // ค่าเริ่มต้นคือ ORGANIZATION จึงให้ผลเท่าเดิม และผู้ดูแลหรี่รายคนได้เอง
            $query = $visibility->applyScope($query, $user);

            // กรองตาม fiscal_year ถ้าส่งมา เพื่อลดข้อมูล
            if ($request->fiscal_year) {
                $fy = FiscalYear::where('year', (int) $request->fiscal_year)->first();
                if ($fy) {
                    $query->whereBetween('start_date', [
                        Carbon::parse($fy->start_date)->startOfDay(),
                        Carbon::parse($fy->end_date)->endOfDay(),
                    ]);
                }
            }

            $paginated = $query->paginate($perPage);

            return response()->json([
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
            ]);
        } else {
            $leaves = Leave::with(['user:id,name,department_id', 'user.department', 'type:id,name', 'approvals.approver:id,name', 'actionLogs.user:id,name'])
                ->where(function ($query) use ($user) {
                    $query->where('user_id', $user->id)
                        ->orWhere('substitute_id', $user->id);
                })
                ->orderBy('created_at', 'desc')
                ->limit(100)
                ->get();

            return response()->json($leaves);
        }
    }

    /**
     * Get leave balances for the current user.
     */
    public function balances(Request $request)
    {
        $userId = $request->user()->id;

        // Determine which fiscal year to use
        $requestedYear = $request->input('fiscal_year');
        if ($requestedYear) {
            $selectedFY = FiscalYear::where('year', (int) $requestedYear)->first();
        } else {
            $selectedFY = FiscalYear::current();
        }

        // ต้องใช้สูตรเดียวกับที่ตอนตัดสิทธิ์ใช้ ไม่งั้นจะอ่าน balance คนละแถวกับที่ระบบเขียน
        $year = $this->policies->fiscalYearNumber(
            $selectedFY,
            $selectedFY ? Carbon::parse($selectedFY->start_date) : today()
        );

        $balances = LeaveBalance::with(['type', 'policy'])
            ->where('user_id', $userId)
            ->where('year', $year)
            ->get();

        $pendingQuery = Leave::selectRaw('leave_type_id, SUM(total_days) as pending_days')
            ->where('user_id', $userId)
            ->where('status', 'Pending');

        $approvedQuery = Leave::selectRaw('leave_type_id, SUM(total_days) as used_days')
            ->where('user_id', $userId)
            ->where('status', 'Approved');

        if ($selectedFY) {
            $startDate = Carbon::parse($selectedFY->start_date)->startOfDay();
            $endDate = Carbon::parse($selectedFY->end_date)->endOfDay();
            $pendingQuery->whereBetween('start_date', [$startDate, $endDate]);
            $approvedQuery->whereBetween('start_date', [$startDate, $endDate]);
        } else {
            $pendingQuery->whereYear('start_date', $year);
            $approvedQuery->whereYear('start_date', $year);
        }

        $pendingLeaves = $pendingQuery->groupBy('leave_type_id')->pluck('pending_days', 'leave_type_id')->toArray();
        $approvedLeaves = $approvedQuery->groupBy('leave_type_id')->pluck('used_days', 'leave_type_id')->toArray();

        foreach ($balances as $balance) {
            // used/reserved ในตารางคือตัวเลขที่ระบบใช้ตัดสิทธิ์จริง จึงต้องเป็นตัวที่แสดงด้วย
            // ส่วนยอดที่คำนวณสดจากตาราง leaves ส่งไปเป็นข้อมูลเทียบ (ไว้ตรวจว่าข้อมูลเพี้ยนหรือไม่)
            $balance->pending = (float) $balance->reserved;
            $balance->leaves_pending_days = (float) ($pendingLeaves[$balance->leave_type_id] ?? 0);
            $balance->leaves_used_days = (float) ($approvedLeaves[$balance->leave_type_id] ?? 0);
            // นโยบายที่ไม่กำหนดโควตา (annual_quota = null) เก็บเป็น 0 ในตาราง ต้องบอกหน้าจอว่า "ไม่จำกัด"
            $balance->unlimited = $balance->relationLoaded('policy')
                && $balance->policy !== null
                && $balance->policy->annual_quota === null;

            // เพดานวันที่ได้รับเงินเดือน (เช่น ลาป่วย 60 วันแรก) — null = ได้รับเงินเดือนทุกวัน
            $paidCap = $balance->policy?->max_days_with_pay;
            $balance->paid_quota = $paidCap === null ? null : (float) $paidCap;
            $balance->paid_remaining = $paidCap === null
                ? null
                : max(0.0, (float) $paidCap - (float) $balance->used - (float) $balance->reserved);
        }

        return response()->json($balances);
    }

    /**
     * Get team leaves for the calendar view.
     */
    /**
     * Get team leaves for the calendar view.
     */
    public function teamCalendar(Request $request)
    {
        $user = $request->user();
        $query = Leave::with(['user.profile', 'user.department', 'type'])
            ->whereIn('status', ['Approved', 'Pending']);

        // LEGACY-04 (04-4): บทบาทที่เคยระบุด้วยชื่อ ล้วนถือ attendance.view_all อยู่แล้ว
        // การเช็ค permission อย่างเดียวจึงให้ผลเท่าชุดเดิมทุกประการ
        $isGlobalViewer = $user->can('attendance.view_all');

        if (! $isGlobalViewer) {
            $departmentId = $user->department_id ?? ($user->profile->department_id ?? null);
            if ($departmentId) {
                $query->whereHas('user', function ($q) use ($departmentId) {
                    $q->where('department_id', $departmentId);
                });
            } else {
                $query->where('user_id', $user->id);
            }
        }

        $year = date('Y');
        $query->where(function ($q) use ($year) {
            $q->whereYear('start_date', $year)
                ->orWhereYear('end_date', $year);
        });

        return response()->json($query->get());
    }

    /**
     * Return all available leave types.
     */
    public function types()
    {
        return response()->json(LeaveType::all());
    }

    /**
     * Submit a new leave request.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'leave_period' => 'nullable|in:full_day,morning,afternoon',
            'reason' => 'required|string|max:1000',
            'contact_address' => 'nullable|string|max:1000',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'substitute_id' => 'nullable|exists:users,id',
            'substitute_reason' => 'nullable|string|max:1000',
            'meta_data' => 'nullable|json',
            'signature_base64' => 'nullable|string',
        ]);

        $leavePeriod = $data['leave_period'] ?? 'full_day';
        if ($leavePeriod !== 'full_day' && $data['start_date'] !== $data['end_date']) {
            return response()->json(['message' => 'การลาครึ่งวันต้องเลือกวันที่เริ่มต้นและสิ้นสุดเป็นวันเดียวกัน'], 422);
        }

        $leaveType = LeaveType::find($data['leave_type_id']);

        // Notice Period & Past Dates Validation
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

        // Leave overlap validation
        $overlapCount = Leave::where('user_id', $request->user()->id)
            ->whereNotIn('status', ['Cancelled', 'Rejected'])
            ->where(function ($query) use ($data) {
                $query->whereBetween('start_date', [$data['start_date'], $data['end_date']])
                    ->orWhereBetween('end_date', [$data['start_date'], $data['end_date']])
                    ->orWhere(function ($q) use ($data) {
                        $q->where('start_date', '<=', $data['start_date'])
                            ->where('end_date', '>=', $data['end_date']);
                    });
            })
            ->count();
        if ($overlapCount > 0) {
            return response()->json(['message' => 'คุณมีใบลาในช่วงเวลานี้อยู่แล้ว ไม่สามารถยื่นซ้ำได้'], 422);
        }

        $signaturePath = $request->user()->profile->signature_path ?? null;

        if ($request->has('signature_base64') && $request->signature_base64) {
            $base64Image = $request->signature_base64;
            if (preg_match('/^data:image\/(\w+);base64,/', $base64Image, $type)) {
                $base64Image = substr($base64Image, strpos($base64Image, ',') + 1);
                $type = strtolower($type[1]);
                if (in_array($type, ['jpg', 'jpeg', 'png'])) {
                    $base64Image = base64_decode($base64Image);
                    $fileName = 'signatures/'.uniqid().'.'.$type;
                    Storage::disk('private_uploads')->put($fileName, $base64Image);
                    $signaturePath = '/storage/'.$fileName;
                }
            }
        }

        $data['signature_path'] = $signaturePath;

        $leave = $this->leaveService->createLeaveRequest(
            $data,
            $request->user()->id,
            $request->file('attachment')
        );

        LeaveActionLog::create([
            'leave_id' => $leave->id,
            'user_id' => $request->user()->id,
            'action' => 'Created',
            'details' => ['reason' => $data['reason']],
        ]);

        // Notify Approvers using Spatie roles & permissions
        $approvers = User::whereHas('roles', function ($q) {
            $q->whereIn('name', [
                'ผู้ดูแลระบบสูงสุด', 'Super Admin',
                'ผู้อำนวยการ', 'Director',
                'รองผู้อำนวยการ', 'Deputy Director',
                'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย', 'Head of Department',
                'เจ้าหน้าที่บุคลากร (HR)', 'HR Head',
            ]);
        })->get();

        foreach ($approvers as $approver) {
            $approver->notify(new LeaveRequested($leave));

            if ($approver->telegram_chat_id) {
                $telegram = new TelegramNotificationService;
                $telegram->sendMessage(
                    $approver->telegram_chat_id,
                    "🔔 <b>มีการยื่นใบลาใหม่</b>\nพนักงาน: {$leave->user->name}\nประเภท: ".($leave->type->name ?? '')."\nวันที่: ".$leave->start_date->format('d/m/Y').' - '.$leave->end_date->format('d/m/Y')
                );
            }
        }

        return response()->json([
            'message' => 'ส่งคำขอลาเรียบร้อยแล้ว',
            'leave' => $leave->load('type'),
        ], 201);
    }

    /**
     * Submit a new leave request as Admin/HR for an employee.
     */
    public function storeAsAdmin(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|max:1000',
        ]);

        $data['contact_address'] = 'บันทึกโดยฝ่ายบุคคล';
        $data['signature_path'] = null;

        $leave = $this->leaveService->createLeaveRequest(
            $data,
            $data['user_id'],
            null
        );

        // Auto approve since HR is entering it
        $leave->status = 'Approved';
        $leave->save();

        LeaveActionLog::create([
            'leave_id' => $leave->id,
            'user_id' => $request->user()->id,
            'action' => 'Created & Approved',
            'details' => ['reason' => 'บันทึกวันลาแทนพนักงานโดยฝ่ายบุคคล: '.$data['reason']],
        ]);

        return response()->json([
            'message' => 'บันทึกวันลาเรียบร้อยแล้ว',
            'leave' => $leave->load('type'),
        ], 201);
    }

    /**
     * Cancel a pending leave request.
     */
    public function cancel(Request $request, Leave $leave)
    {
        $user = $request->user();
        /*
         * LEGACY-04 (04-4): เดิมชุดผู้มีสิทธิ์ยกเลิกใบลาของผู้อื่นคือ
         * {Super Admin, HR Head} โดย HR Head ผ่านด้วย **ชื่อ role เท่านั้น**
         * เพราะยังไม่มีใครถือ `leave.management.manage` จริง
         *
         * `system.user.view` เป็นสิทธิ์ที่ HR Head ถืออยู่และ Director/Deputy/
         * Document Officer ไม่ได้ถือ จึงให้ชุดผู้มีสิทธิ์เท่าเดิมพอดี
         *
         * ยอมรับว่าชื่อสิทธิ์ไม่สื่อความหมายกับการยกเลิกใบลา — ควรตั้ง permission
         * เฉพาะทาง (เช่น leave.request.cancel_any) ตอนจัดระเบียบ permission ชุดใหญ่
         */
        $isAdminOrHR = $user->can('leave.management.manage') || $user->can('system.user.view');

        // Only the owner or Admin/HR can cancel
        if ($leave->user_id !== $user->id && ! $isAdminOrHR) {
            return response()->json(['message' => 'ไม่มีสิทธิ์ยกเลิกใบลานี้'], 403);
        }

        if ($leave->status === 'Pending') {
            // ต้องคืนวันลาที่จองไว้ (reserved) ก่อน ไม่งั้นสิทธิ์จะค้างหายถาวร
            $this->leaveService->restoreBalance($leave);
            $leave->status = 'Cancelled';
            $leave->save();

            LeaveActionLog::create([
                'leave_id' => $leave->id,
                'user_id' => $user->id,
                'action' => 'Cancelled',
                'details' => ['reason' => 'User cancelled pending leave'],
            ]);

            return response()->json(['message' => 'ยกเลิกใบลาเรียบร้อยแล้ว']);
        } elseif ($leave->status === 'Approved') {
            $request->validate(['cancellation_reason' => 'required|string|max:1000']);

            $leave->cancellation_status = 'Pending';
            $leave->cancellation_reason = $request->cancellation_reason;
            $leave->cancellation_date = now();

            $firstStep = ApprovalStep::orderBy('step_order', 'asc')->first();
            $leave->approval_level = $firstStep ? $firstStep->step_order : 1;

            $leave->save();

            LeaveActionLog::create([
                'leave_id' => $leave->id,
                'user_id' => $user->id,
                'action' => 'Cancellation Requested',
                'details' => ['reason' => $request->cancellation_reason],
            ]);

            return response()->json(['message' => 'ส่งคำขอยกเลิกใบลาที่อนุมัติแล้วเรียบร้อย กรุณารอการอนุมัติยกเลิก']);
        }

        return response()->json(['message' => 'ไม่สามารถยกเลิกใบลานี้ได้'], 422);
    }

    /**
     * Approve leave cancellation
     */
    public function approveCancellation(Request $request, Leave $leave)
    {
        $user = $request->user();
        // LEGACY-04 (04-4): Super Admin ยังผ่านทาง Gate::before
        // และยังไม่มีผู้ใช้รายอื่นถือสิทธิ์นี้ พฤติกรรมจึงเท่าเดิม
        $isSuperAdmin = $user->can('leave.management.manage');

        if (! $isSuperAdmin) {
            $currentStep = ApprovalStep::where('step_order', $leave->approval_level)->first();
            // LEGACY-04 (04-7): `users.role_id` ถูกลบไปแล้วใน LEGACY-03 และกลายเป็น
            // accessor ที่คืน roles->first()->id จึงซ้ำกับบรรทัดนี้พอดี (DB-RISK-002)
            $userRoleIds = $user->roles->pluck('id')->unique()->values()->all();

            if (! $currentStep || ! in_array($currentStep->role_id, $userRoleIds)) {
                return response()->json(['message' => 'ไม่มีสิทธิ์อนุมัติในระดับนี้'], 403);
            }
        }

        $nextStep = null;
        if (! $isSuperAdmin) {
            $nextStep = ApprovalStep::where('step_order', '>', $leave->approval_level)
                ->orderBy('step_order', 'asc')
                ->first();
        }

        if (! $nextStep) {
            $leave->cancellation_status = 'Approved';
            $leave->status = 'Cancelled';
            $this->leaveService->restoreBalance($leave);

            LeaveActionLog::create([
                'leave_id' => $leave->id,
                'user_id' => $user->id,
                'action' => 'Cancellation Approved',
                'details' => $request->has('comment') && $request->comment ? ['comment' => $request->comment] : null,
            ]);
        } else {
            $leave->approval_level = $nextStep->step_order;
            LeaveActionLog::create([
                'leave_id' => $leave->id,
                'user_id' => $user->id,
                'action' => 'Cancellation Step Approved',
                'details' => $request->has('comment') && $request->comment ? ['comment' => $request->comment] : null,
            ]);
        }

        $leave->save();

        return response()->json(['message' => 'อนุมัติการยกเลิกใบลาเรียบร้อยแล้ว']);
    }

    /**
     * Reject leave cancellation
     */
    public function rejectCancellation(Request $request, Leave $leave)
    {
        $user = $request->user();
        // LEGACY-04 (04-4): Super Admin ยังผ่านทาง Gate::before
        // และยังไม่มีผู้ใช้รายอื่นถือสิทธิ์นี้ พฤติกรรมจึงเท่าเดิม
        $isSuperAdmin = $user->can('leave.management.manage');

        if (! $isSuperAdmin) {
            $currentStep = ApprovalStep::where('step_order', $leave->approval_level)->first();
            // LEGACY-04 (04-7): `users.role_id` ถูกลบไปแล้วใน LEGACY-03 และกลายเป็น
            // accessor ที่คืน roles->first()->id จึงซ้ำกับบรรทัดนี้พอดี (DB-RISK-002)
            $userRoleIds = $user->roles->pluck('id')->unique()->values()->all();

            if (! $currentStep || ! in_array($currentStep->role_id, $userRoleIds)) {
                return response()->json(['message' => 'ไม่มีสิทธิ์ปฏิเสธในระดับนี้'], 403);
            }
        }

        $leave->cancellation_status = 'Rejected';
        $leave->save();

        LeaveActionLog::create([
            'leave_id' => $leave->id,
            'user_id' => $user->id,
            'action' => 'Cancellation Rejected',
            'details' => $request->has('comment') && $request->comment ? ['comment' => $request->comment] : null,
        ]);

        return response()->json(['message' => 'ปฏิเสธการยกเลิกใบลาเรียบร้อยแล้ว']);
    }

    /**
     * HR Verification Step
     */
    public function hrVerify(Request $request, Leave $leave)
    {
        // SEC-004 — เดิมไม่มีการตรวจสิทธิ์เลย ผู้ใช้ทั่วไปรับรองสถิติของใครก็ได้
        $this->authorize('hrVerify', $leave);

        $leave->hr_verified_by = $request->user()->id;
        $leave->hr_verified_at = now();
        $leave->save();

        LeaveActionLog::create([
            'leave_id' => $leave->id,
            'user_id' => $request->user()->id,
            'action' => 'HR Verified',
        ]);

        return response()->json(['message' => 'ตรวจสอบสถิติการลาเรียบร้อยแล้ว']);
    }

    /**
     * Substitute response (Accept)
     */
    public function acceptSubstitute(Request $request, Leave $leave)
    {
        if ($leave->substitute_id !== $request->user()->id) {
            return response()->json(['message' => 'ไม่มีสิทธิ์'], 403);
        }

        $leave->substitute_status = 'Accepted';
        $leave->save();

        LeaveActionLog::create([
            'leave_id' => $leave->id,
            'user_id' => $request->user()->id,
            'action' => 'Substitute Accepted',
        ]);

        return response()->json(['message' => 'รับทราบการมอบหมายงานเรียบร้อยแล้ว']);
    }

    /**
     * Substitute response (Reject)
     */
    public function rejectSubstitute(Request $request, Leave $leave)
    {
        if ($leave->substitute_id !== $request->user()->id) {
            return response()->json(['message' => 'ไม่มีสิทธิ์'], 403);
        }

        $leave->substitute_status = 'Rejected';
        $leave->save();

        LeaveActionLog::create([
            'leave_id' => $leave->id,
            'user_id' => $request->user()->id,
            'action' => 'Substitute Rejected',
        ]);

        return response()->json(['message' => 'ปฏิเสธการรับมอบหมายงานเรียบร้อยแล้ว']);
    }

    /**
     * Delete a leave request (Admin/HR only)
     */
    public function destroy(Request $request, Leave $leave)
    {
        $user = $request->user();
        $isAdminOrHR = $user->can('leave.management.manage');

        $canDelete = false;
        if ($isAdminOrHR) {
            $canDelete = true;
        } elseif ($leave->user_id === $user->id && $leave->status === 'Pending') {
            $cancelDays = (int) (Setting::where('key', 'cancel_allowed_days')->value('value') ?? 5);
            if ($leave->created_at->diffInDays(now()) <= $cancelDays) {
                $canDelete = true;
            }
        }

        if (! $canDelete) {
            return response()->json(['message' => 'ไม่มีสิทธิ์ลบใบลานี้'], 403);
        }

        // คืนสิทธิ์ทั้งกรณี Pending (ปลด reserved) และ Approved (ปลด used)
        if (in_array($leave->status, ['Pending', 'Approved'], true)) {
            $this->leaveService->restoreBalance($leave);
        }

        $leaveId = $leave->id;
        $leave->delete();

        // Not inserting to ActionLog because the leave is deleted, but could if we used soft deletes.

        return response()->json(['message' => 'ลบใบลาเรียบร้อยแล้ว']);
    }

    /**
     * Update a leave request (Admin/HR only)
     */
    public function update(Request $request, Leave $leave)
    {
        $user = $request->user();
        if (! $user->can('leave.management.manage')) {
            return response()->json(['message' => 'ไม่มีสิทธิ์แก้ไขใบลานี้'], 403);
        }

        $data = $request->validate([
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|max:1000',
        ]);

        $leave->update($data);

        LeaveActionLog::create([
            'leave_id' => $leave->id,
            'user_id' => $user->id,
            'action' => 'Updated',
            'details' => $data,
        ]);

        return response()->json(['message' => 'แก้ไขใบลาเรียบร้อยแล้ว', 'leave' => $leave->load('type')]);
    }

    /**
     * Export a leave as PDF.
     */
    public function exportPdf(Request $request, Leave $leave, ThaiDateService $thaiDateService)
    {
        // SEC-003 — เอกสารมีเหตุผลการลา ที่อยู่ติดต่อ และลายเซ็นของผู้ลาอยู่ในนั้น
        $this->authorize('view', $leave);

        $leave->load(['user.department', 'user.roles', 'user.profile', 'type', 'approvals.approver']);

        $lastLeave = Leave::where('user_id', $leave->user_id)
            ->where('leave_type_id', $leave->leave_type_id)
            ->where('status', 'Approved')
            ->where('id', '<', $leave->id)
            ->orderBy('id', 'desc')
            ->first();

        $settings = Setting::pluck('value', 'key');

        $approvalSteps = ApprovalStep::with('role')->orderBy('step_order')->get();
        $roleSettingMap = [
            'HR Head' => 'head_hr_name',
            'Deputy Director' => 'deputy_admin_name',
            'Director' => 'director_name',
        ];
        $isCancel = $request->query('form') === 'cancel';
        $cancelLog = null;
        if ($isCancel) {
            $cancelLog = LeaveActionLog::with('user.profile')
                ->where('leave_id', $leave->id)
                ->whereIn('action', ['Cancellation Approved', 'Cancellation Rejected'])
                ->orderBy('id', 'desc')
                ->first();
        }

        $approverBlocks = [];
        foreach ($approvalSteps as $step) {
            $roleName = $step->role ? $step->role->name : '';

            if ($isCancel && $roleName === 'Director' && $cancelLog) {
                $status = $cancelLog->action === 'Cancellation Approved' ? 'Approved' : 'Rejected';
                $approverBlocks[] = [
                    'name' => $cancelLog->user->full_name_with_title ?? $cancelLog->user->name,
                    'date' => Carbon::parse($cancelLog->created_at),
                    'signature' => $cancelLog->user->profile && $cancelLog->user->profile->signature_path ? StoredFile::path($cancelLog->user->profile->signature_path) : null,
                    'comment' => $cancelLog->details['comment'] ?? null,
                    'status' => $status,
                    'position' => $cancelLog->user->profile->position ?? ($step->role->description ?? $step->name),
                    'role_name' => $roleName,
                ];

                continue;
            }

            $approval = $leave->approvals->filter(fn ($a) => $a->approver && $a->approver->role_id === $step->role_id)->first();
            $settingKey = $roleSettingMap[$roleName] ?? '';
            $defaultName = $settingKey && isset($settings[$settingKey]) ? $settings[$settingKey] : '........................................................';

            $positionTitle = $step->role ? ($step->role->description ?? $step->name) : $step->name;

            $approverBlocks[] = [
                'name' => $approval ? ($approval->approver_name ?: $approval->approver->name) : $defaultName,
                'date' => $approval ? Carbon::parse($approval->updated_at) : null,
                'signature' => $approval && $approval->signature_path ? StoredFile::path($approval->signature_path) : null,
                'comment' => $approval ? $approval->comment : null,
                'status' => $approval ? $approval->status : null,
                'position' => $positionTitle,
                'role_name' => $roleName,
            ];
        }

        $viewName = 'pdf.leave';
        if ($isCancel) {
            $viewName = 'pdf.leave_cancel';
        } elseif ($leave->type) {
            $tName = $leave->type->name;
            if ($tName === 'ลาพักผ่อน') {
                $viewName = 'pdf.leave_vacation';
            } elseif (str_contains($tName, 'ราชการ') || str_contains($tName, 'เดินทาง')) {
                $viewName = 'pdf.official_travel';
            } elseif (str_contains($tName, 'ช่วยเหลือภริยา')) {
                $viewName = 'pdf.leave_paternity';
            } elseif ($tName === 'ลาอุปสมบท') {
                $viewName = 'pdf.leave_ordination';
            } elseif ($tName === 'ลาไปประกอบพิธีฮัจญ์') {
                $viewName = 'pdf.leave_hajj';
            } elseif ($tName === 'ลาติดตามคู่สมรส') {
                $viewName = 'pdf.leave_spouse';
            } elseif ($tName === 'ลาไปศึกษาต่อ ดูงาน ฝึกอบรม') {
                $viewName = 'pdf.leave_study';
            }
        }

        $leaveBalance = null;
        if ($viewName === 'pdf.leave_vacation') {
            $year = Carbon::parse($leave->start_date)->year;
            $leaveBalance = LeaveBalance::where('user_id', $leave->user_id)
                ->where('leave_type_id', $leave->leave_type_id)
                ->where('year', $year)
                ->first();
        }

        // Calculate 2 Fiscal Semesters Stats for Standard Leave Form
        $statSem1 = ['ป่วย' => ['used_times' => '', 'used_days' => ''], 'กิจ' => ['used_times' => '', 'used_days' => ''], 'คลอด' => ['used_times' => '', 'used_days' => '']];
        $statSem2 = ['ป่วย' => ['used_times' => '', 'used_days' => ''], 'กิจ' => ['used_times' => '', 'used_days' => ''], 'คลอด' => ['used_times' => '', 'used_days' => '']];

        // QR ตรวจสอบเอกสาร ฝังเป็น data URI เพราะ dompdf โหลดรูปจาก URL ภายนอกไม่ได้
        $verification = app(LeaveVerificationService::class);
        $verificationHash = $verification->ensureHash($leave);
        $verificationQr = $verification->qrCodeDataUri($verificationHash);
        $verificationUrl = $verification->verificationUrl($verificationHash);

        $pdf = Pdf::loadView($viewName, compact(
            'leave', 'thaiDateService', 'lastLeave', 'leaveBalance', 'settings',
            'approverBlocks', 'statSem1', 'statSem2',
            'verificationQr', 'verificationUrl', 'verificationHash'
        ))->setPaper('a4', 'portrait');

        $leaveTypeName = $leave->type->name ?? 'การลา';
        $userName = str_replace(' ', '_', trim($leave->user->name));
        $docNumber = $leave->document_number ?? $leave->id;

        $baseFilename = "{$leaveTypeName}_{$userName}_{$docNumber}";
        $baseFilename = str_replace(['/', '\\'], '_', $baseFilename);

        $filename = $isCancel ? "cancel_{$baseFilename}.pdf" : "{$baseFilename}.pdf";

        return $pdf->download($filename);
    }

    /**
     * Export a leave as Word.
     */
    public function exportWord(Request $request, Leave $leave, ThaiDateService $thaiDateService)
    {
        // SEC-003 — เช่นเดียวกับ exportPdf
        $this->authorize('view', $leave);

        $leave->load(['user.department', 'user.roles', 'user.profile', 'type', 'approvals.approver']);

        $lastLeave = Leave::where('user_id', $leave->user_id)
            ->where('leave_type_id', $leave->leave_type_id)
            ->where('status', 'Approved')
            ->where('id', '<', $leave->id)
            ->orderBy('id', 'desc')
            ->first();

        $settings = Setting::pluck('value', 'key');

        $approvalSteps = ApprovalStep::with('role')->orderBy('step_order')->get();
        $roleSettingMap = [
            'HR Head' => 'head_hr_name',
            'Deputy Director' => 'deputy_admin_name',
            'Director' => 'director_name',
        ];
        $isCancel = $request->query('form') === 'cancel';
        $cancelLog = null;
        if ($isCancel) {
            $cancelLog = LeaveActionLog::with('user.profile')
                ->where('leave_id', $leave->id)
                ->whereIn('action', ['Cancellation Approved', 'Cancellation Rejected'])
                ->orderBy('id', 'desc')
                ->first();
        }

        $approverBlocks = [];
        foreach ($approvalSteps as $step) {
            $roleName = $step->role ? $step->role->name : '';

            if ($isCancel && $roleName === 'Director' && $cancelLog) {
                $status = $cancelLog->action === 'Cancellation Approved' ? 'Approved' : 'Rejected';
                $approverBlocks[] = [
                    'name' => $cancelLog->user->full_name_with_title ?? $cancelLog->user->name,
                    'date' => Carbon::parse($cancelLog->created_at),
                    'signature' => $cancelLog->user->profile && $cancelLog->user->profile->signature_path ? StoredFile::path($cancelLog->user->profile->signature_path) : null,
                    'comment' => $cancelLog->details['comment'] ?? null,
                    'status' => $status,
                    'position' => $cancelLog->user->profile->position ?? ($step->role->description ?? $step->name),
                    'role_name' => $roleName,
                ];

                continue;
            }

            $approval = $leave->approvals->filter(fn ($a) => $a->approver && $a->approver->role_id === $step->role_id)->first();
            $settingKey = $roleSettingMap[$roleName] ?? '';
            $defaultName = $settingKey && isset($settings[$settingKey]) ? $settings[$settingKey] : '........................................................';

            $positionTitle = $step->role ? ($step->role->description ?? $step->name) : $step->name;

            $approverBlocks[] = [
                'name' => $approval ? ($approval->approver_name ?: $approval->approver->name) : $defaultName,
                'date' => $approval ? Carbon::parse($approval->updated_at) : null,
                'signature' => $approval && $approval->signature_path ? StoredFile::path($approval->signature_path) : null,
                'comment' => $approval ? $approval->comment : null,
                'status' => $approval ? $approval->status : null,
                'position' => $positionTitle,
                'role_name' => $roleName,
            ];
        }

        $viewName = 'pdf.leave';
        $isCancel = $request->query('form') === 'cancel';
        if ($isCancel) {
            $viewName = 'pdf.leave_cancel';
        } elseif ($leave->type) {
            if ($leave->type->name === 'ลาพักผ่อน') {
                $viewName = 'pdf.leave_vacation';
            } elseif ($leave->type->name === 'ลาอุปสมบท') {
                $viewName = 'pdf.leave_ordination';
            } elseif ($leave->type->name === 'ลาไปประกอบพิธีฮัจญ์') {
                $viewName = 'pdf.leave_hajj';
            } elseif ($leave->type->name === 'ลาติดตามคู่สมรส') {
                $viewName = 'pdf.leave_spouse';
            } elseif ($leave->type->name === 'ลาไปศึกษาต่อ ดูงาน ฝึกอบรม') {
                $viewName = 'pdf.leave_study';
            }
        }

        $leaveBalance = null;
        if ($viewName === 'pdf.leave_vacation') {
            $year = Carbon::parse($leave->start_date)->year;
            $leaveBalance = LeaveBalance::where('user_id', $leave->user_id)
                ->where('leave_type_id', $leave->leave_type_id)
                ->where('year', $year)
                ->first();
        }

        $year = Carbon::parse($leave->start_date)->year;
        $balancesQuery = LeaveBalance::with('type')
            ->where('user_id', $leave->user_id)
            ->where('year', $year)
            ->get();

        $statBalances = [];
        foreach ($balancesQuery as $b) {
            if (! $b->type) {
                continue;
            }
            $typeName = $b->type->name;
            $used = $b->used;
            if ($leave->status === 'Approved' && ($leave->type->name ?? '') === $typeName) {
                $usedBefore = max(0, $used - $leave->total_days);
                $total = $used;
            } else {
                $usedBefore = $used;
                $total = $used + (($leave->type->name ?? '') === $typeName ? $leave->total_days : 0);
            }
            $statBalances[$typeName] = [
                'used_before' => $usedBefore > 0 ? $usedBefore : '',
                'total' => $total > 0 ? $total : '',
            ];
        }

        $leaveTypeName = $leave->type->name ?? 'การลา';
        $userName = str_replace(' ', '_', trim($leave->user->name));
        $docNumber = $leave->document_number ?? $leave->id;

        $baseFilename = "{$leaveTypeName}_{$userName}_{$docNumber}";
        $baseFilename = str_replace(['/', '\\'], '_', $baseFilename);

        if (class_exists(PhpWord::class)) {
            $wordService = app(LeaveWordService::class);
            $tempFile = $wordService->generateLeaveDocx($leave, $settings->toArray(), $approverBlocks);
            $filename = $isCancel ? "cancel_{$baseFilename}.docx" : "{$baseFilename}.docx";

            return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
        }

        $statSem1 = ['ป่วย' => ['used_times' => '', 'used_days' => ''], 'กิจ' => ['used_times' => '', 'used_days' => ''], 'คลอด' => ['used_times' => '', 'used_days' => '']];
        $statSem2 = ['ป่วย' => ['used_times' => '', 'used_days' => ''], 'กิจ' => ['used_times' => '', 'used_days' => ''], 'คลอด' => ['used_times' => '', 'used_days' => '']];

        $html = view($viewName, compact('leave', 'thaiDateService', 'lastLeave', 'leaveBalance', 'settings', 'approverBlocks', 'statSem1', 'statSem2'))->render();
        $content = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"></head><body>'.$html.'</body></html>';
        $filename = $isCancel ? "cancel_{$baseFilename}.doc" : "{$baseFilename}.doc";

        return response($content)
            ->header('Content-Type', 'application/vnd.ms-word')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }
}
