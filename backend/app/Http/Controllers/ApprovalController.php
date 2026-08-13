<?php

namespace App\Http\Controllers;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\LeaveApproval;
use App\Models\User;
use App\Models\UserDelegation;
use App\Notifications\LeaveStatusChanged;
use App\Services\LeaveService;
use App\Services\LeaveVisibility;
use App\Services\LineNotificationService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ApprovalController extends Controller
{
    protected LeaveService $leaveService;

    protected TelegramService $telegramService;

    public function __construct(LeaveService $leaveService, TelegramService $telegramService)
    {
        $this->leaveService = $leaveService;
        $this->telegramService = $telegramService;
    }

    public function pending(Request $request, LeaveVisibility $visibility)
    {
        $user = $request->user();

        // LEGACY-04 (04-4): เดิมเช็คชื่อ role ตรง ๆ
        // Super Admin ยังผ่านได้เหมือนเดิมผ่าน Gate::before และปัจจุบันยังไม่มี
        // ผู้ใช้รายอื่นถือสิทธิ์นี้ พฤติกรรมจึงเท่าเดิมทุกประการ
        $canManageAll = $user->can('leave.management.manage');

        if ($canManageAll) {
            $leaves = Leave::with(['user.profile', 'user.department', 'type:id,name', 'approvals.approver:id,name'])
                ->where(function ($query) {
                    $query->where('status', 'Pending')
                        ->orWhere('cancellation_status', 'Pending');
                })
                ->orderBy('created_at', 'asc')
                ->get();
        } else {
            // Get user's own role IDs (both Spatie roles and legacy role_id)
            $userRoleIds = $user->roles->pluck('id')->toArray();
            if ($user->role_id) {
                $userRoleIds[] = $user->role_id;
            }
            $userRoleIds = array_unique($userRoleIds);

            // Get user's approval steps
            $userRoleSteps = ApprovalStep::whereIn('role_id', $userRoleIds)->pluck('step_order')->toArray();

            // Check active delegations
            $activeDelegations = UserDelegation::where('delegate_id', $user->id)
                ->where('is_active', true)
                ->where('start_date', '<=', now())
                ->where('end_date', '>=', now())
                ->with('user.roles')
                ->get();

            foreach ($activeDelegations as $delegation) {
                if ($delegation->user) {
                    $delRoleIds = $delegation->user->roles->pluck('id')->toArray();
                    if ($delegation->user->role_id) {
                        $delRoleIds[] = $delegation->user->role_id;
                    }
                    $delegatorSteps = ApprovalStep::whereIn('role_id', array_unique($delRoleIds))->pluck('step_order')->toArray();
                    $userRoleSteps = array_unique(array_merge($userRoleSteps, $delegatorSteps));
                }
            }

            if (empty($userRoleSteps)) {
                return response()->json([]);
            }

            $query = Leave::with(['user.profile', 'user.department', 'type:id,name', 'approvals.approver:id,name'])
                ->where(function ($q) {
                    $q->where('status', 'Pending')
                        ->orWhere('cancellation_status', 'Pending');
                })
                ->whereIn('approval_level', $userRoleSteps);

            // LEGACY-04 (04-4): เดิมหรี่ขอบเขตด้วยชื่อ role `หัวหน้าแผนกวิชา / หัวหน้าฝ่าย`
            // ซึ่งไม่มีอยู่จริงในฐานข้อมูล ตรรกะนี้จึงไม่เคยทำงาน
            // ตอนนี้ใช้ permission_scopes ค่าเริ่มต้น ORGANIZATION จึงให้ผลเท่าเดิม
            $query = $visibility->applyScope($query, $user);

            $leaves = $query->orderBy('created_at', 'asc')->get();
        }

        return response()->json($leaves);
    }

    public function approve(Request $request, Leave $leave)
    {
        $user = $request->user();
        // LEGACY-04 (04-4): เลิกเช็คชื่อ role — Super Admin ยังผ่านทาง Gate::before
        // และยังไม่มีผู้ใช้รายอื่นถือสิทธิ์นี้ พฤติกรรมจึงเท่าเดิม
        $isSuperAdmin = $user->can('leave.management.manage');

        // Only pending leaves can be approved
        if ($leave->status !== 'Pending') {
            return response()->json(['message' => 'ใบลานี้ไม่อยู่ในสถานะรออนุมัติ'], 422);
        }

        // Check if substitute has accepted assignment (if substitute is assigned)
        if ($leave->substitute_id && $leave->substitute_status !== 'Accepted') {
            return response()->json([
                'message' => 'ไม่สามารถอนุมัติได้เนื่องจากผู้ปฏิบัติงานแทนยังไม่ได้ตอบรับการมอบหมายงาน',
            ], 422);
        }

        // Check if user is authorized to approve this level
        if (! $isSuperAdmin) {
            $currentStep = ApprovalStep::where('step_order', $leave->approval_level)->first();
            $userRoleIds = $user->roles->pluck('id')->toArray();
            if ($user->role_id) {
                $userRoleIds[] = $user->role_id;
            }

            $isAuthorized = false;
            if ($currentStep && in_array($currentStep->role_id, $userRoleIds)) {
                $isAuthorized = true;
            } elseif ($currentStep) {
                $isDelegate = UserDelegation::where('delegate_id', $user->id)
                    ->where('is_active', true)
                    ->where('start_date', '<=', now())
                    ->where('end_date', '>=', now())
                    ->whereHas('user', function ($q) use ($currentStep) {
                        $q->where('role_id', $currentStep->role_id)
                            ->orWhereHas('roles', fn ($rq) => $rq->where('roles.id', $currentStep->role_id));
                    })->exists();
                if ($isDelegate) {
                    $isAuthorized = true;
                }
            }

            if (! $isAuthorized) {
                return response()->json(['message' => 'ไม่มีสิทธิ์อนุมัติในระดับนี้'], 403);
            }
        }

        DB::transaction(function () use ($leave, $user, $request, $isSuperAdmin) {
            $profile = $user->profile;
            $signaturePath = $profile ? $profile->signature_path : null;

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

            LeaveApproval::create([
                'leave_id' => $leave->id,
                'approver_id' => $user->id,
                'approver_name' => $user->full_name_with_title,
                'approver_position' => $profile ? $profile->position : null,
                'signature_path' => $signaturePath,
                'status' => 'Approved',
                'comment' => $request->comment,
                'approved_at' => now(),
            ]);

            LeaveActionLog::create([
                'leave_id' => $leave->id,
                'user_id' => $user->id,
                'action' => 'Step Approved (Level '.$leave->approval_level.')',
                'details' => $request->has('comment') && $request->comment ? ['comment' => $request->comment] : null,
            ]);

            // Check if there is a next step
            $nextStep = null;
            if (! $isSuperAdmin) {
                $nextStep = ApprovalStep::where('step_order', '>', $leave->approval_level)
                    ->orderBy('step_order', 'asc')
                    ->first();
            }

            if (! $nextStep) {
                // Final level reached — check balance then deduct
                if (! $this->leaveService->hasEnoughBalance($leave)) {
                    throw new \Exception('วันลาคงเหลือไม่เพียงพอ');
                }
                $this->leaveService->deductBalance($leave);
                $leave->status = 'Approved';
                if ($isSuperAdmin && ! $leave->hr_verified_at) {
                    $leave->hr_verified_at = now();

                    // Auto-fill signatures for all approval steps (HR, Deputy, Director)
                    $steps = ApprovalStep::orderBy('step_order', 'asc')->get();
                    foreach ($steps as $st) {
                        $stepUser = User::where('role_id', $st->role_id)
                            ->orWhereHas('roles', fn ($rq) => $rq->where('roles.id', $st->role_id))
                            ->first();

                        if ($stepUser) {
                            $stepProfile = $stepUser->profile;
                            LeaveApproval::firstOrCreate([
                                'leave_id' => $leave->id,
                                'approver_id' => $stepUser->id,
                            ], [
                                'approver_name' => $stepUser->full_name_with_title ?? $stepUser->name,
                                'approver_position' => $stepProfile ? $stepProfile->position : null,
                                'signature_path' => $stepProfile ? $stepProfile->signature_path : null,
                                'status' => 'Approved',
                                'comment' => '-',
                                'approved_at' => now(),
                            ]);

                            LeaveActionLog::firstOrCreate([
                                'leave_id' => $leave->id,
                                'action' => 'Auto-Approved Step (Level '.$st->step_order.')',
                            ], [
                                'user_id' => $user->id,
                                'details' => ['reason' => 'Auto-approved by Super Admin'],
                            ]);
                        }
                    }
                }
            } else {
                // Move to next level
                $leave->approval_level = $nextStep->step_order;
            }
            $leave->save();
        });

        // Notify user if fully approved
        if ($leave->status === 'Approved') {
            $leave->user->notify(new LeaveStatusChanged($leave, 'Approved', $user->name));
            $startDateStr = $leave->start_date ? $leave->start_date->format('d/m/Y') : '';
            $endDateStr = $leave->end_date ? $leave->end_date->format('d/m/Y') : '';
            $msg = "✅ ใบลาของท่านได้รับการอนุมัติเรียบร้อยแล้ว\n"
                 .'ประเภท: '.($leave->type->name ?? 'การลา')."\n"
                 ."ตั้งแต่วันที่: {$startDateStr}\n"
                 ."ถึงวันที่: {$endDateStr}";

            if ($leave->user->telegram_chat_id) {
                $this->telegramService->sendMessage($leave->user->telegram_chat_id, $msg);
            }

            $lineService = new LineNotificationService;
            $lineService->sendMessage($msg, $leave->user->line_user_id ?? null);
        }

        return response()->json(['message' => 'อนุมัติเรียบร้อยแล้ว']);
    }

    public function reject(Request $request, Leave $leave)
    {
        $user = $request->user();
        // LEGACY-04 (04-4): เลิกเช็คชื่อ role — Super Admin ยังผ่านทาง Gate::before
        // และยังไม่มีผู้ใช้รายอื่นถือสิทธิ์นี้ พฤติกรรมจึงเท่าเดิม
        $isSuperAdmin = $user->can('leave.management.manage');

        // Only pending leaves can be rejected
        if ($leave->status !== 'Pending') {
            return response()->json(['message' => 'ใบลานี้ไม่อยู่ในสถานะรออนุมัติ'], 422);
        }

        // Check if user is authorized to reject this level
        if (! $isSuperAdmin) {
            $currentStep = ApprovalStep::where('step_order', $leave->approval_level)->first();
            $userRoleIds = $user->roles->pluck('id')->toArray();
            if ($user->role_id) {
                $userRoleIds[] = $user->role_id;
            }

            $isAuthorized = false;
            if ($currentStep && in_array($currentStep->role_id, $userRoleIds)) {
                $isAuthorized = true;
            } elseif ($currentStep) {
                $isDelegate = UserDelegation::where('delegate_id', $user->id)
                    ->where('is_active', true)
                    ->where('start_date', '<=', now())
                    ->where('end_date', '>=', now())
                    ->whereHas('user', function ($q) use ($currentStep) {
                        $q->where('role_id', $currentStep->role_id)
                            ->orWhereHas('roles', fn ($rq) => $rq->where('roles.id', $currentStep->role_id));
                    })->exists();
                if ($isDelegate) {
                    $isAuthorized = true;
                }
            }

            if (! $isAuthorized) {
                return response()->json(['message' => 'ไม่มีสิทธิ์อนุมัติในระดับนี้'], 403);
            }
        }

        DB::transaction(function () use ($leave, $user, $request) {
            $profile = $user->profile;
            $signaturePath = $profile ? $profile->signature_path : null;

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

            LeaveApproval::create([
                'leave_id' => $leave->id,
                'approver_id' => $user->id,
                'approver_name' => $user->full_name_with_title,
                'approver_position' => $profile ? $profile->position : null,
                'signature_path' => $signaturePath,
                'status' => 'Rejected',
                'comment' => $request->comment,
                'approved_at' => now(),
            ]);

            LeaveActionLog::create([
                'leave_id' => $leave->id,
                'user_id' => $user->id,
                'action' => 'Step Rejected (Level '.$leave->approval_level.')',
                'details' => $request->has('comment') && $request->comment ? ['comment' => $request->comment] : null,
            ]);

            $leave->status = 'Rejected';
            $leave->save();
        });

        $leave->user->notify(new LeaveStatusChanged($leave, 'Rejected', $user->name));
        if ($leave->user->telegram_chat_id) {
            $msg = "❌ ใบลาของท่านถูกปฏิเสธ\n";
            $msg .= "ประเภท: {$leave->type->name}\n";
            $msg .= 'เหตุผล: '.($request->comment ?: 'ไม่มี');
            $this->telegramService->sendMessage($leave->user->telegram_chat_id, $msg);
        }

        return response()->json(['message' => 'ปฏิเสธการลาเรียบร้อยแล้ว']);
    }
}
