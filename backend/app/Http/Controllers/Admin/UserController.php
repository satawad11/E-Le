<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Spatie\Permission\Models\Role;
use App\Models\Department;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::with(['roles', 'department', 'profile'])->orderBy('name', 'asc')->get();

        // คืนทะเบียนประวัติของบุคลากรทุกคน จึงถือเป็นการเข้าถึงข้อมูลส่วนบุคคลแบบรวม
        app(\App\Services\PersonalDataAccessLogger::class)
            ->recordBulk($request, 'user_directory', $users->count(), 'เปิดหน้าจัดการผู้ใช้งาน (ทะเบียนประวัติบุคลากรทั้งหมด)');

        return response()->json($users);
    }

    public function list(Request $request)
    {
        $users = User::select('id', 'name', 'department_id')->orderBy('name', 'asc')->get();
        return response()->json($users);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'nullable|string|min:6',
            'role_id' => 'required|exists:roles,id',
            'department_id' => 'required|exists:departments,id',
            'telegram_chat_id' => 'nullable|string|max:255',
            'personnel_type_id' => 'nullable|exists:personnel_types,id',
            'profile' => 'nullable|array',
            'custom_permissions' => 'nullable',
        ]);

        $profileData = $request->has('profile') ? (is_string($request->profile) ? json_decode($request->profile, true) : $request->profile) : [];
        $idCard = $profileData['id_card_number'] ?? null;
        
        $password = $request->filled('password') ? $request->password : ($idCard ?: 'password');

        $user = DB::transaction(function() use ($request, $profileData, $password) {
            $role = Role::find($request->role_id);

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($password),
                // ไม่ใส่ role_id — เป็น accessor จาก Spatie ไม่ใช่คอลัมน์ (กำหนดผ่าน syncRoles() ด้านล่าง)
                'department_id' => $request->department_id,
                'telegram_chat_id' => $request->telegram_chat_id,
                // ต้องมีประเภทบุคลากรเสมอ ไม่งั้นนโยบายสิทธิการลาจะหาไม่เจอ
                'personnel_type_id' => $request->personnel_type_id
                    ?: \App\Models\PersonnelType::where('code', 'civil_servant')->value('id'),
            ]);

            if ($role) {
                $user->syncRoles([$role->name]);
            }

            if ($request->has('custom_permissions')) {
                $perms = is_string($request->custom_permissions) ? json_decode($request->custom_permissions, true) : $request->custom_permissions;
                if (is_array($perms)) {
                    $user->syncPermissions($perms);
                    $user->custom_permissions = $perms;
                    $user->save();
                }
            }

            if ($request->hasFile('profile_photo')) {
                $path = $request->file('profile_photo')->store('profile_photos', 'public');
                $profileData['profile_photo_path'] = '/storage/' . $path;
            }
            $user->profile()->create($profileData);

            // สร้างสิทธิ์ปีปัจจุบันจาก "นโยบายสิทธิการลา" ตามประเภทบุคลากร
            // (เดิมอ่านจาก leave_types.max_days ทำให้ได้ตัวเลขคนละชุดกับที่ระบบใช้ตัดสิทธิ์)
            $policies = app(\App\Services\LeavePolicyService::class);
            $today = today();
            $year = $policies->fiscalYearNumber(\App\Models\FiscalYear::current(), $today);

            foreach (LeaveType::all() as $lt) {
                $policy = \App\Models\LeavePolicy::where('personnel_type_id', $user->personnel_type_id)
                    ->where('leave_type_id', $lt->id)
                    ->where('is_active', true)
                    ->whereDate('effective_from', '<=', $today)
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
                    ->orderByDesc('effective_from')
                    ->first();

                LeaveBalance::firstOrCreate(
                    ['user_id' => $user->id, 'leave_type_id' => $lt->id, 'year' => $year],
                    ['quota' => $policy?->annual_quota ?? 0, 'used' => 0, 'reserved' => 0, 'carried_forward' => 0, 'leave_policy_id' => $policy?->id]
                );
            }

            return $user;
        });

        return response()->json([
            'message' => 'สร้างผู้ใช้เรียบร้อยแล้ว',
            'user' => $user->load('roles', 'department', 'profile')
        ], 201);
    }

    public function show(Request $request, User $user)
    {
        app(\App\Services\PersonalDataAccessLogger::class)
            ->record($request, $user->id, 'user_profile', 'เปิดดูทะเบียนประวัติบุคลากร');

        return response()->json($user->load('roles', 'department', 'profile'));
    }

    public function update(Request $request, User $user)
    {
        app(\App\Services\PersonalDataAccessLogger::class)
            ->record($request, $user->id, 'user_profile_edit', 'แก้ไขทะเบียนประวัติบุคลากร');

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:6',
            'role_id' => 'required|exists:roles,id',
            'department_id' => 'required|exists:departments,id',
            'telegram_chat_id' => 'nullable|string|max:255',
            'profile' => 'nullable|array',
            'custom_permissions' => 'nullable',
        ]);

        $user->name = $request->name;
        $user->email = $request->email;
        // role_id เป็น accessor ที่อ่านมาจาก Spatie ไม่ใช่คอลัมน์จริง — บทบาทกำหนดผ่าน syncRoles() ด้านล่าง
        $user->department_id = $request->department_id;
        $user->telegram_chat_id = $request->telegram_chat_id;

        if ($request->filled('password')) {
            $user->password = bcrypt($request->password);
        }

        $role = Role::find($request->role_id);
        if ($role) {
            $user->syncRoles([$role->name]);
        }

        if ($request->has('custom_permissions')) {
            $perms = is_string($request->custom_permissions) ? json_decode($request->custom_permissions, true) : $request->custom_permissions;
            if (is_array($perms)) {
                $user->syncPermissions($perms);
                $user->custom_permissions = $perms;
            }
        }

        $user->save();

        if ($request->has('profile')) {
            $profileData = is_string($request->profile) ? json_decode($request->profile, true) : $request->profile;
            if ($request->hasFile('profile_photo')) {
                $path = $request->file('profile_photo')->store('profile_photos', 'public');
                $profileData['profile_photo_path'] = '/storage/' . $path;
            }
            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                $profileData
            );
        } else if ($request->hasFile('profile_photo')) {
            $path = $request->file('profile_photo')->store('profile_photos', 'public');
            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                ['profile_photo_path' => '/storage/' . $path]
            );
        }

        return response()->json([
            'message' => 'อัปเดตข้อมูลผู้ใช้เรียบร้อยแล้ว',
            'user' => $user->load('roles', 'department', 'profile')
        ]);
    }

    public function destroy(User $user)
    {
        $user->delete();
        return response()->json(['message' => 'ลบผู้ใช้เรียบร้อยแล้ว']);
    }
}
