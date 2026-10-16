<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserDelegation;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return response()->json($request->user()->load('profile', 'department', 'roles'));
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'phone' => 'nullable|string|max:20',
            'telegram_chat_id' => 'nullable|string|max:255',
            'line_user_id' => 'nullable|string|max:255',
            'notification_channel' => 'nullable|string|in:line,telegram,both,none',
            'password' => 'nullable|string|min:12',
            'current_password' => 'nullable|string',
            'profile' => 'nullable|array',
            'signature_file' => 'nullable|image|max:2048',
            'signature_base64' => 'nullable|string',
        ]);

        // ต้องยืนยันรหัสผ่านเดิม — กันกรณี token/เครื่องที่ค้าง login ถูกใช้ยึดบัญชี
        if ($request->filled('password') && ! Hash::check((string) $request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['รหัสผ่านปัจจุบันไม่ถูกต้อง'],
            ]);
        }

        $user->phone = $request->phone;
        $user->telegram_chat_id = $request->telegram_chat_id;
        $user->line_user_id = $request->line_user_id;
        $user->notification_channel = $request->notification_channel ?? 'both';

        if ($request->filled('password')) {
            $user->password = bcrypt($request->password);
        }

        $user->save();

        if ($request->has('profile')) {
            $allowedProfileFields = [
                'house_registration_number', 'house_number', 'moo', 'soi', 'road',
                'province', 'district', 'sub_district', 'zipcode',
                'fax', 'website',
                // ไม่รับ 'religion'/'ethnicity' อีกต่อไป — ข้อมูลอ่อนไหวตาม ม.26 ที่ระบบไม่ได้ใช้
            ];

            $profileData = is_string($request->profile) ? json_decode($request->profile, true) : $request->profile;
            $filteredData = [];

            foreach ($allowedProfileFields as $field) {
                if (isset($profileData[$field])) {
                    $filteredData[$field] = $profileData[$field];
                }
            }

            if ($request->hasFile('profile_photo')) {
                $path = $request->file('profile_photo')->store('profile_photos', 'private_uploads');
                $filteredData['profile_photo_path'] = '/storage/'.$path;
            }

            if ($request->hasFile('signature_file')) {
                $path = $request->file('signature_file')->store('signatures', 'private_uploads');
                $filteredData['signature_path'] = '/storage/'.$path;
            } elseif ($request->filled('signature_base64')) {
                $base64 = $request->signature_base64;
                if (preg_match('/^data:image\/(\w+);base64,/', $base64, $type)) {
                    $base64 = substr($base64, strpos($base64, ',') + 1);
                    $type = strtolower($type[1]); // jpg, png, gif
                    $base64 = base64_decode($base64);
                    if ($base64 !== false) {
                        $fileName = 'signatures/'.uniqid().'.'.$type;
                        Storage::disk('private_uploads')->put($fileName, $base64);
                        $filteredData['signature_path'] = '/storage/'.$fileName;
                    }
                }
            }

            if (! empty($filteredData)) {
                $user->profile()->updateOrCreate(
                    ['user_id' => $user->id],
                    $filteredData
                );
            }
        } else {
            $filteredData = [];
            if ($request->hasFile('profile_photo')) {
                $path = $request->file('profile_photo')->store('profile_photos', 'private_uploads');
                $filteredData['profile_photo_path'] = '/storage/'.$path;
            }
            if ($request->hasFile('signature_file')) {
                $path = $request->file('signature_file')->store('signatures', 'private_uploads');
                $filteredData['signature_path'] = '/storage/'.$path;
            } elseif ($request->filled('signature_base64')) {
                $base64 = $request->signature_base64;
                if (preg_match('/^data:image\/(\w+);base64,/', $base64, $type)) {
                    $base64 = substr($base64, strpos($base64, ',') + 1);
                    $type = strtolower($type[1]);
                    $base64 = base64_decode($base64);
                    if ($base64 !== false) {
                        $fileName = 'signatures/'.uniqid().'.'.$type;
                        Storage::disk('private_uploads')->put($fileName, $base64);
                        $filteredData['signature_path'] = '/storage/'.$fileName;
                    }
                }
            }

            if (! empty($filteredData)) {
                $user->profile()->updateOrCreate(
                    ['user_id' => $user->id],
                    $filteredData
                );
            }
        }

        return response()->json([
            'message' => 'อัปเดตข้อมูลส่วนตัวเรียบร้อยแล้ว',
            'user' => $user->load('profile', 'department', 'roles'),
        ]);
    }

    /** คำขอ/รายการทำหน้าที่แทนที่ผู้ใช้เป็นผู้มอบ (DELEGATION-01) */
    public function getDelegation(Request $request)
    {
        $delegations = UserDelegation::with('delegate.profile')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (UserDelegation $d) => [
                'id' => $d->id,
                'delegate_id' => $d->delegate_id,
                'delegate' => $d->delegate ? ['id' => $d->delegate->id, 'name' => $d->delegate->full_name_with_title] : null,
                'delegation_type' => $d->delegation_type,
                'acting_line' => $d->actingLine(),
                'start_date' => $d->start_date?->toDateString(),
                'end_date' => $d->end_date?->toDateString(),
                'order_number' => $d->order_number,
                'status' => $d->status,
                'is_active' => $d->is_active,
            ]);

        return response()->json($delegations);
    }

    /**
     * ส่งคำขอให้ผู้อื่นทำหน้าที่แทน — มีผลเมื่องานบุคลากรรับรองตามคำสั่งแล้วเท่านั้น
     *
     * เดิมผู้ใช้ตั้งผู้รับมอบเองแล้วมีผลทันที ใครก็ได้จึงตั้งให้อีกคนลงนามอนุมัติแทนตนได้โดยไม่มีคำสั่ง
     */
    public function setDelegation(Request $request, AuditLogger $audit)
    {
        $data = $request->validate([
            'delegate_id' => 'required|integer|exists:users,id',
            'delegation_type' => ['nullable', Rule::in(UserDelegation::TYPES)],
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'note' => 'nullable|string|max:500',
        ]);

        abort_if((int) $data['delegate_id'] === $request->user()->id, 422, 'ไม่สามารถมอบหมายให้ตนเองได้');
        abort_unless(User::whereKey($data['delegate_id'])->where('is_active', true)->exists(), 422, 'ผู้ทำหน้าที่แทนต้องเป็นบัญชีที่ใช้งานอยู่');

        $delegation = UserDelegation::create([
            'user_id' => $request->user()->id,
            'delegate_id' => $data['delegate_id'],
            'delegation_type' => $data['delegation_type'] ?? UserDelegation::TYPE_ON_BEHALF,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'note' => $data['note'] ?? null,
            'is_active' => true,
            'status' => UserDelegation::STATUS_PENDING,
            'requested_by' => $request->user()->id,
        ]);

        $audit->log('delegation_requested', $delegation, after: $delegation->toArray());

        return response()->json([
            'message' => 'ส่งคำขอเรียบร้อยแล้ว — มีผลเมื่องานบุคลากรรับรองตามคำสั่งแต่งตั้ง',
            'delegation' => $delegation,
        ], 201);
    }
}
