<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LeavePolicyService;
use App\Services\PersonalDataAccessLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::with(['roles', 'department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'orgUnit', 'personnelType'])->orderBy('name', 'asc')->get();

        // คืนทะเบียนประวัติของบุคลากรทุกคน จึงถือเป็นการเข้าถึงข้อมูลส่วนบุคคลแบบรวม
        app(PersonalDataAccessLogger::class)
            ->recordBulk($request, 'user_directory', $users->count(), 'เปิดหน้าจัดการผู้ใช้งาน (ทะเบียนประวัติบุคลากรทั้งหมด)');

        return response()->json($users);
    }

    public function list(Request $request)
    {
        $users = User::select('id', 'uuid', 'name', 'department_id', 'org_unit_id')->with('personnel:id,user_id,uuid,personnel_code')->orderBy('name', 'asc')->get();

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:12',
            'role_id' => 'required|exists:roles,id',
            'department_id' => 'required|exists:departments,id',
            'telegram_chat_id' => 'nullable|string|max:255',
            'personnel_type_id' => 'nullable|exists:personnel_types,id',
            'profile' => 'nullable|array',
            'custom_permissions' => 'nullable',
        ]);

        $profileData = $request->has('profile') ? (is_string($request->profile) ? json_decode($request->profile, true) : $request->profile) : [];
        $idCard = $profileData['id_card_number'] ?? null;

        $password = $request->password;

        $user = DB::transaction(function () use ($request, $profileData, $password) {
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
                    ?: PersonnelType::where('code', 'civil_servant')->value('id'),
            ]);

            if ($role) {
                $user->syncRoles([$role->name]);
            }

            if ($request->has('custom_permissions')) {
                $perms = is_string($request->custom_permissions) ? json_decode($request->custom_permissions, true) : $request->custom_permissions;
                if (is_array($perms)) {
                    /*
                     * LEGACY-04 (04-6): syncPermissions() บันทึกลง pivot ของ Spatie แล้ว
                     * เดิมยังเขียนซ้ำลงคอลัมน์ `users.custom_permissions` ซึ่งเป็นแหล่งสิทธิ์
                     * ที่สอง (DB-RISK-009) และคอลัมน์นั้นถูกลบไปแล้วใน LEGACY-03
                     * การเขียนต่อจึงทำให้การกำหนดสิทธิ์รายบุคคลพังทั้งฟีเจอร์
                     */
                    $user->syncPermissions($perms);
                }
            }

            if ($request->hasFile('profile_photo')) {
                $path = $request->file('profile_photo')->store('profile_photos', 'private_uploads');
                $profileData['profile_photo_path'] = '/storage/'.$path;
            }
            $user->profile()->create($profileData);

            // สร้างสิทธิ์ปีปัจจุบันจาก "นโยบายสิทธิการลา" ตามประเภทบุคลากร
            // (เดิมอ่านจาก leave_types.max_days ทำให้ได้ตัวเลขคนละชุดกับที่ระบบใช้ตัดสิทธิ์)
            $policies = app(LeavePolicyService::class);
            $today = today();
            $year = $policies->fiscalYearNumber(FiscalYear::current(), $today);

            $activePolicies = LeavePolicy::where('personnel_type_id', $user->personnel_type_id)
                ->where('is_active', true)
                ->whereDate('effective_from', '<=', $today)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
                ->orderByDesc('effective_from')
                ->get()
                ->groupBy('leave_type_id');

            foreach (LeaveType::all() as $lt) {
                $policy = $activePolicies->get($lt->id)?->first();

                LeaveBalance::firstOrCreate(
                    ['user_id' => $user->id, 'leave_type_id' => $lt->id, 'year' => $year],
                    ['quota' => $policy?->annual_quota ?? 0, 'used' => 0, 'reserved' => 0, 'carried_forward' => 0, 'leave_policy_id' => $policy?->id]
                );
            }

            return $user;
        });

        // คอลัมน์ของ users ถูก audit โดย model hook อยู่แล้ว (AppServiceProvider)
        // ที่ต้องบันทึกเพิ่มคือบทบาท/สิทธิ์ และชื่อฟิลด์ทะเบียนประวัติ
        $this->auditAccessChange($user, null);
        $this->auditProfileFields($user, array_keys($profileData));

        return response()->json([
            'message' => 'สร้างผู้ใช้เรียบร้อยแล้ว',
            'user' => $user->load(['roles', 'department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'orgUnit', 'personnelType']),
        ], 201);
    }

    public function show(Request $request, User $user)
    {
        app(PersonalDataAccessLogger::class)
            ->record($request, $user->id, 'user_profile', 'เปิดดูทะเบียนประวัติบุคลากร');

        return response()->json($user->load(['roles', 'department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'orgUnit', 'personnelType']));
    }

    public function update(Request $request, User $user)
    {
        app(PersonalDataAccessLogger::class)
            ->record($request, $user->id, 'user_profile_edit', 'แก้ไขทะเบียนประวัติบุคลากร');

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$user->id,
            'password' => 'nullable|string|min:12',
            'role_id' => 'required|exists:roles,id',
            'department_id' => 'required|exists:departments,id',
            'telegram_chat_id' => 'nullable|string|max:255',
            'profile' => 'nullable|array',
            'custom_permissions' => 'nullable',
        ]);

        $accessBefore = $this->accessSnapshot($user);

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
                // LEGACY-04 (04-6): แหล่งสิทธิ์รายบุคคลมีที่เดียวคือ Spatie
                $user->syncPermissions($perms);
            }
        }

        $user->save();

        if ($request->has('profile')) {
            $profileData = is_string($request->profile) ? json_decode($request->profile, true) : $request->profile;
            if ($request->hasFile('profile_photo')) {
                $path = $request->file('profile_photo')->store('profile_photos', 'private_uploads');
                $profileData['profile_photo_path'] = '/storage/'.$path;
            }
            $profile = $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                $profileData
            );
        } elseif ($request->hasFile('profile_photo')) {
            $path = $request->file('profile_photo')->store('profile_photos', 'private_uploads');
            $profile = $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                ['profile_photo_path' => '/storage/'.$path]
            );
        }

        $this->auditAccessChange($user, $accessBefore);
        if (isset($profile)) {
            $this->auditProfileFields($user, array_keys($profile->wasRecentlyCreated ? $profile->getAttributes() : $profile->getChanges()));
        }
        if ($request->filled('password')) {
            app(AuditLogger::class)->log('user.password_reset_by_admin', 'user', $user->id);
        }

        return response()->json([
            'message' => 'อัปเดตข้อมูลผู้ใช้เรียบร้อยแล้ว',
            'user' => $user->load(['roles', 'department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'orgUnit', 'personnelType']),
        ]);
    }

    /**
     * ไม่ลบบัญชีผู้ใช้จริง — การลบจะ cascade ลบใบลา ยอดวันลา การลงเวลา ทะเบียนบุคลากร
     * และบันทึกการเข้าถึงข้อมูลส่วนบุคคลของผู้ใช้นั้นทิ้งถาวร (บางตารางเป็น restrict จน error 500)
     * ซึ่งขัดกับกฎห้ามลบประวัติ บุคลากรที่พ้นสภาพให้ใช้การ "ระงับการใช้งาน" แทน
     */
    public function destroy(User $user)
    {
        return response()->json([
            'message' => 'ระบบไม่อนุญาตให้ลบบัญชีผู้ใช้ เพื่อรักษาประวัติการลา การลงเวลา และทะเบียนประวัติบุคลากร กรุณาใช้ "ระงับการใช้งาน" แทน',
        ], 409);
    }

    public function toggleStatus(Request $request, User $user)
    {
        if ($user->is($request->user()) && $user->is_active) {
            return response()->json(['message' => 'ไม่สามารถระงับการใช้งานบัญชีของตนเองได้'], 422);
        }

        // การเปลี่ยน is_active ถูก audit (user.updated) และ token ถูกยกเลิก (UserObserver) ใน model hook แล้ว
        $user->is_active = ! $user->is_active;
        $user->save();

        return response()->json([
            'message' => $user->is_active ? 'เปิดใช้งานผู้ใช้แล้ว' : 'ระงับการใช้งานผู้ใช้แล้ว',
            'user' => $user->fresh()->load(['roles', 'department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'orgUnit', 'personnelType']),
        ]);
    }

    private function accessSnapshot(User $user): array
    {
        return [
            'roles' => $user->roles()->pluck('name')->sort()->values()->all(),
            'direct_permissions' => $user->getDirectPermissions()->pluck('name')->sort()->values()->all(),
        ];
    }

    private function auditAccessChange(User $user, ?array $before): void
    {
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $after = $this->accessSnapshot($user);

        if ($before !== $after) {
            app(AuditLogger::class)->log('user.access_changed', 'user', $user->id, $before, $after);
        }
    }

    /**
     * บันทึกเฉพาะ "ชื่อฟิลด์" ทะเบียนประวัติที่เปลี่ยน ไม่บันทึกค่า
     * — ไม่ให้ audit log กลายเป็นสำเนาเลขบัตรประชาชน/ที่อยู่
     */
    private function auditProfileFields(User $user, array $fields): void
    {
        $fields = array_values(array_diff($fields, ['id', 'user_id', 'id_card_number_hash', 'created_at', 'updated_at']));

        if ($fields !== []) {
            app(AuditLogger::class)->log('user.profile_updated', 'user', $user->id, null, ['fields' => $fields]);
        }
    }
}
