<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Services\LeaveService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;

class ApprovalController extends Controller
{
    protected LeaveService $leaveService;
    protected TelegramService $telegramService;

    public function __construct(LeaveService $leaveService, TelegramService $telegramService)
    {
        $this->leaveService = $leaveService;
        $this->telegramService = $telegramService;
    }

    public function pending(Request $request)
    {
        $user = $request->user();
        $roleName = $user->role->name ?? '';

        if ($roleName === 'Super Admin') {
            $leaves = Leave::with(['user.profile', 'type:id,name', 'approvals.approver:id,name'])
                ->where(function($query) {
                    $query->where('status', 'Pending')
                          ->orWhere('cancellation_status', 'Pending');
                })
                ->orderBy('created_at', 'asc')
                ->get();
        } else {
            // Get user's own approval steps
            $userRoleSteps = \App\Models\ApprovalStep::where('role_id', $user->role_id)->pluck('step_order')->toArray();

            // Check if user is a delegate for anyone
            $activeDelegations = \App\Models\UserDelegation::where('delegate_id', $user->id)
                ->where('is_active', true)
                ->where('start_date', '<=', now())
                ->where('end_date', '>=', now())
                ->with('user.role')
                ->get();

            // Add delegator's approval steps
            foreach ($activeDelegations as $delegation) {
                if ($delegation->user && $delegation->user->role_id) {
                    $delegatorSteps = \App\Models\ApprovalStep::where('role_id', $delegation->user->role_id)->pluck('step_order')->toArray();
                    $userRoleSteps = array_unique(array_merge($userRoleSteps, $delegatorSteps));
                }
            }

            if (empty($userRoleSteps)) {
                return response()->json([]);
            }

            $leaves = Leave::with(['user.profile', 'type:id,name', 'approvals.approver:id,name'])
                ->where(function($query) {
                    $query->where('status', 'Pending')
                          ->orWhere('cancellation_status', 'Pending');
                })
                ->whereIn('approval_level', $userRoleSteps)
                ->orderBy('created_at', 'asc')
                ->get();
        }

        return response()->json($leaves);
    }

    public function approve(Request $request, Leave $leave)
    {
        $user = $request->user();
        $role = $user->role->name;

        // Only pending leaves can be approved
        if ($leave->status !== 'Pending') {
            return response()->json(['message' => 'ใบลานี้ไม่อยู่ในสถานะรออนุมัติ'], 422);
        }

        // Check if user is authorized to approve this level
        if ($role !== 'Super Admin') {
            $currentStep = \App\Models\ApprovalStep::where('step_order', $leave->approval_level)->first();
            
            $isAuthorized = false;
            if ($currentStep && $currentStep->role_id === $user->role_id) {
                $isAuthorized = true;
            } else if ($currentStep) {
                $isDelegate = \App\Models\UserDelegation::where('delegate_id', $user->id)
                    ->where('is_active', true)
                    ->where('start_date', '<=', now())
                    ->where('end_date', '>=', now())
                    ->whereHas('user', function($q) use ($currentStep) {
                        $q->where('role_id', $currentStep->role_id);
                    })->exists();
                if ($isDelegate) {
                    $isAuthorized = true;
                }
            }

            if (!$isAuthorized) {
                return response()->json(['message' => 'ไม่มีสิทธิ์อนุมัติในระดับนี้'], 403);
            }
        }

        DB::transaction(function () use ($leave, $user, $request, $role) {
            $profile = $user->profile;
            $signaturePath = $profile ? $profile->signature_path : null;

            if ($request->has('signature_base64') && $request->signature_base64) {
                $base64Image = $request->signature_base64;
                if (preg_match('/^data:image\/(\w+);base64,/', $base64Image, $type)) {
                    $base64Image = substr($base64Image, strpos($base64Image, ',') + 1);
                    $type = strtolower($type[1]);
                    if (in_array($type, ['jpg', 'jpeg', 'png'])) {
                        $base64Image = base64_decode($base64Image);
                        $fileName = 'signatures/' . uniqid() . '.' . $type;
                        \Illuminate\Support\Facades\Storage::disk('public')->put($fileName, $base64Image);
                        $signaturePath = '/storage/' . $fileName;
                    }
                }
            }

            LeaveApproval::create([
                'leave_id'          => $leave->id,
                'approver_id'       => $user->id,
                'approver_name'     => $user->full_name_with_title,
                'approver_position' => $profile ? $profile->position : null,
                'signature_path'    => $signaturePath,
                'status'            => 'Approved',
                'comment'           => $request->comment,
                'approved_at'       => now(),
            ]);

            \App\Models\LeaveActionLog::create([
                'leave_id' => $leave->id,
                'user_id' => $user->id,
                'action' => 'Step Approved (Level ' . $leave->approval_level . ')',
                'details' => $request->has('comment') && $request->comment ? ['comment' => $request->comment] : null
            ]);

            // Check if there is a next step
            $nextStep = null;
            if ($role !== 'Super Admin') {
                $nextStep = \App\Models\ApprovalStep::where('step_order', '>', $leave->approval_level)
                    ->orderBy('step_order', 'asc')
                    ->first();
            }

            if (!$nextStep) {
                // Final level reached — check balance then deduct
                if (!$this->leaveService->hasEnoughBalance($leave)) {
                    throw new \Exception('วันลาคงเหลือไม่เพียงพอ');
                }
                $this->leaveService->deductBalance($leave);
                $leave->status = 'Approved';
                if ($role === 'Super Admin' && !$leave->hr_verified_at) {
                    $leave->hr_verified_at = now();

                    // Auto-fill signatures for all approval steps (HR, Deputy, Director)
                    $steps = \App\Models\ApprovalStep::orderBy('step_order', 'asc')->get();
                    foreach ($steps as $st) {
                        $stepUser = \App\Models\User::where('role_id', $st->role_id)->first();
                        if ($stepUser) {
                            $stepProfile = $stepUser->profile;
                            \App\Models\LeaveApproval::firstOrCreate([
                                'leave_id'    => $leave->id,
                                'approver_id' => $stepUser->id,
                            ], [
                                'approver_name'     => $stepUser->full_name_with_title ?? $stepUser->name,
                                'approver_position' => $stepProfile ? $stepProfile->position : null,
                                'signature_path'    => $stepProfile ? $stepProfile->signature_path : null,
                                'status'            => 'Approved',
                                'comment'           => '-',
                                'approved_at'       => now(),
                            ]);

                            \App\Models\LeaveActionLog::firstOrCreate([
                                'leave_id' => $leave->id,
                                'action' => 'Auto-Approved Step (Level ' . $st->step_order . ')',
                            ], [
                                'user_id' => $user->id,
                                'details' => ['reason' => 'Auto-approved by Super Admin']
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
            $leave->user->notify(new \App\Notifications\LeaveStatusChanged($leave, 'Approved', $user->name));
            if ($leave->user->telegram_chat_id) {
                $msg = "✅ ใบลาของท่านได้รับการอนุมัติเรียบร้อยแล้ว\n";
                $msg .= "ประเภท: {$leave->type->name}\n";
                $msg .= "ตั้งแต่วันที่: {$leave->start_date->format('Y-m-d')}\n";
                $msg .= "ถึงวันที่: {$leave->end_date->format('Y-m-d')}";
                $this->telegramService->sendMessage($leave->user->telegram_chat_id, $msg);
            }
        }

        return response()->json(['message' => 'อนุมัติเรียบร้อยแล้ว']);
    }

    public function reject(Request $request, Leave $leave)
    {
        $user = $request->user();
        $role = $user->role->name;

        // Only pending leaves can be rejected
        if ($leave->status !== 'Pending') {
            return response()->json(['message' => 'ใบลานี้ไม่อยู่ในสถานะรออนุมัติ'], 422);
        }

        // Check if user is authorized to reject this level
        if ($role !== 'Super Admin') {
            $currentStep = \App\Models\ApprovalStep::where('step_order', $leave->approval_level)->first();
            
            $isAuthorized = false;
            if ($currentStep && $currentStep->role_id === $user->role_id) {
                $isAuthorized = true;
            } else if ($currentStep) {
                $isDelegate = \App\Models\UserDelegation::where('delegate_id', $user->id)
                    ->where('is_active', true)
                    ->where('start_date', '<=', now())
                    ->where('end_date', '>=', now())
                    ->whereHas('user', function($q) use ($currentStep) {
                        $q->where('role_id', $currentStep->role_id);
                    })->exists();
                if ($isDelegate) {
                    $isAuthorized = true;
                }
            }

            if (!$isAuthorized) {
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
                        $fileName = 'signatures/' . uniqid() . '.' . $type;
                        \Illuminate\Support\Facades\Storage::disk('public')->put($fileName, $base64Image);
                        $signaturePath = '/storage/' . $fileName;
                    }
                }
            }

            LeaveApproval::create([
                'leave_id'          => $leave->id,
                'approver_id'       => $user->id,
                'approver_name'     => $user->full_name_with_title,
                'approver_position' => $profile ? $profile->position : null,
                'signature_path'    => $signaturePath,
                'status'            => 'Rejected',
                'comment'           => $request->comment,
                'approved_at'       => now(),
            ]);

            \App\Models\LeaveActionLog::create([
                'leave_id' => $leave->id,
                'user_id' => $user->id,
                'action' => 'Step Rejected (Level ' . $leave->approval_level . ')',
                'details' => $request->has('comment') && $request->comment ? ['comment' => $request->comment] : null
            ]);

            $leave->status = 'Rejected';
            $leave->save();
        });

        $leave->user->notify(new \App\Notifications\LeaveStatusChanged($leave, 'Rejected', $user->name));
        if ($leave->user->telegram_chat_id) {
            $msg = "❌ ใบลาของท่านถูกปฏิเสธ\n";
            $msg .= "ประเภท: {$leave->type->name}\n";
            $msg .= "เหตุผล: " . ($request->comment ?: 'ไม่มี');
            $this->telegramService->sendMessage($leave->user->telegram_chat_id, $msg);
        }

        return response()->json(['message' => 'ปฏิเสธการลาเรียบร้อยแล้ว']);
    }
}
