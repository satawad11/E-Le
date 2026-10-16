<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\Position;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\AuditLogger;
use App\Services\LeavePolicyService;
use App\Services\PersonalDataAccessLogger;
use App\Services\PersonnelSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    // K-ORG-01 — บทบาทที่ได้ทุกสิทธิ์ผ่าน Gate::before และข้อความเมื่อผู้ที่ไม่มี `admin.users.manage_permissions` พยายามมอบบทบาท/สิทธิ์
    private const SUPER_ADMIN_ROLES = ['Super Admin', 'SuperAdmin'];

    private const DENY_ROLE = 'ไม่สามารถกำหนดบทบาทที่มีสิทธิ์สูงกว่าบทบาทของท่านได้';

    private const DENY_ROLE_CHANGE = 'ท่านไม่มีสิทธิ์เปลี่ยนบทบาทของผู้ใช้ (ต้องมีสิทธิ์ "กำหนดสิทธิ์รายบุคคล")';

    private const DENY_PERMISSIONS = 'ท่านไม่มีสิทธิ์กำหนดสิทธิ์รายบุคคล (ต้องมีสิทธิ์ "กำหนดสิทธิ์รายบุคคล")';

    private const DENY_CREDENTIALS = 'ไม่สามารถเปลี่ยนอีเมลหรือรหัสผ่านของผู้ใช้ที่มีสิทธิ์สูงกว่าท่านได้';

    public function index(Request $request)
    {
        // `permissions` = สิทธิ์รายบุคคล (direct) ของ Spatie — ฟอร์มแก้ไขใช้ตั้ง checkbox และส่งกลับมาทุกครั้งที่บันทึก
        // ถ้าไม่ส่งไป ฟอร์มจะส่ง [] แล้วการบันทึกจะลบสิทธิ์รายบุคคลทิ้ง
        $users = User::with(['roles', 'permissions:id,name', 'department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'currentAssignment.positionRef.group', 'orgUnit', 'personnelType'])->orderBy('name', 'asc')->get();

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

    /**
     * บทบาทที่ผู้ใช้ปัจจุบันมอบให้ผู้อื่นได้ (K-ORG-01)
     *
     * `GET /admin/roles` เป็นของผู้จัดการบทบาท (`system.menu.manage`) ฟอร์มผู้ใช้จึงใช้รายการนี้แทน
     */
    public function assignableRoles(Request $request)
    {
        $actor = $request->user();
        $roles = Role::with('permissions:id,name')->orderBy('name')->get();

        if (! $this->canManageAccess($actor)) {
            $roles = $roles->filter(fn (Role $role) => $this->roleWithinActor($actor, $role));
        }

        return response()->json([
            'roles' => $roles->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name])->values(),
            'can_manage_access' => $this->canManageAccess($actor),
        ]);
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
            'position_uuid' => 'nullable|uuid',
            'profile' => 'nullable|array',
            'custom_permissions' => 'nullable',
        ]);

        $position = $this->requestedPosition($request, null);

        // K-ORG-01: ผู้ที่ไม่มีสิทธิ์กำหนดสิทธิ์ สร้างผู้ใช้ได้เฉพาะบทบาทที่ไม่เกินของตนเอง และไม่มีสิทธิ์รายบุคคล
        $actor = $request->user();
        if (! $this->canManageAccess($actor)) {
            $this->ensureRoleWithinActor($actor, Role::find($request->role_id));
            abort_if(($this->requestedPermissions($request) ?? []) !== [], 403, self::DENY_PERMISSIONS);
        }

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
                $perms = $this->requestedPermissions($request);
                if ($perms !== null) {
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

            $this->provisionLeaveBalances($user);

            return $user;
        });

        // คอลัมน์ของ users ถูก audit โดย model hook อยู่แล้ว (AppServiceProvider)
        // ที่ต้องบันทึกเพิ่มคือบทบาท/สิทธิ์ และชื่อฟิลด์ทะเบียนประวัติ
        $this->auditAccessChange($user, null);
        $this->auditProfileFields($user, array_keys($profileData));

        if ($request->exists('position_uuid')) {
            app(PersonnelSyncService::class)->assignCatalogPosition($user->fresh(), $position);
        }

        return response()->json([
            'message' => 'สร้างผู้ใช้เรียบร้อยแล้ว',
            'user' => $user->load(['roles', 'department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'currentAssignment.positionRef.group', 'orgUnit', 'personnelType']),
        ], 201);
    }

    /**
     * นำเข้าผู้ใช้จากไฟล์ CSV
     *
     * ปุ่มนำเข้าในหน้าจัดการผู้ใช้มีมาตลอด แต่เมธอดนี้หายไปใน baseline snapshot (LEGACY-00)
     * route จึงชี้ไปยังเมธอดที่ไม่มีอยู่และตอบ 500 ทุกครั้ง
     *
     * ข้อกำหนดของการนำเข้าชุดนี้:
     *   - ไม่รับรหัสผ่านจากไฟล์ ระบบสุ่มรหัสผ่านที่ไม่มีใครรู้ให้ และไม่ส่งกลับ
     *     ผู้ใช้ใหม่ต้องตั้งรหัสผ่านเองผ่าน "ลืมรหัสผ่าน"
     *   - แถวใดผิดพลาดให้ข้ามแถวนั้นและรายงานเลขบรรทัด ไม่ล้มทั้งไฟล์
     *   - เลขบัตรประชาชนซ้ำกับที่มีอยู่ไม่ได้ เพราะการเข้าสู่ระบบด้วยเลขบัตรค้นจาก hash
     *     แล้วหยิบแถวแรกที่เจอ ถ้าซ้ำจะเข้าบัญชีผิดคน
     *   - บทบาทที่กำหนดได้เท่ากับที่ store() กำหนดได้ — ไม่เกินบทบาทของผู้นำเข้า เว้นแต่ถือ `admin.users.manage_permissions` (K-ORG-01)
     */
    public function importCsv(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        abort_if($handle === false, 422, 'เปิดไฟล์ที่อัปโหลดไม่ได้');

        $header = fgetcsv($handle);
        if ($header === false || $header === [null]) {
            fclose($handle);

            throw ValidationException::withMessages(['file' => ['ไฟล์ว่างเปล่า']]);
        }

        // ตัด BOM ของ Excel ออกจากหัวคอลัมน์แรก ไม่งั้นชื่อคอลัมน์แรกจะไม่ตรง
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(fn ($column) => trim((string) $column), $header);

        $missing = array_diff(['name', 'email', 'role_id', 'department_id'], $header);
        if ($missing !== []) {
            fclose($handle);

            throw ValidationException::withMessages([
                'file' => ['ไฟล์ขาดคอลัมน์ที่จำเป็น: '.implode(', ', $missing)],
            ]);
        }

        $profileColumns = ['id_card_number', 'title', 'first_name', 'last_name', 'gender', 'position'];
        $actor = $request->user();
        $canManageAccess = $this->canManageAccess($actor);
        $importedIds = [];
        $errors = [];
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if ($row === [null] || $row === []) {
                continue;
            }

            $values = array_pad(array_slice($row, 0, count($header)), count($header), null);
            // ช่องว่างในไฟล์ถือเป็น "ไม่ระบุ" ไม่ใช่สตริงว่าง
            $data = array_map(fn ($value) => filled($value) ? trim((string) $value) : null, array_combine($header, $values));

            $validator = Validator::make($data, [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email',
                'role_id' => 'required|exists:roles,id',
                'department_id' => 'required|exists:departments,id',
                'personnel_type_id' => 'nullable|exists:personnel_types,id',
                'phone' => 'nullable|string|max:20',
                'id_card_number' => 'nullable|digits:13',
                'title' => 'nullable|string|max:255',
                'first_name' => 'nullable|string|max:255',
                'last_name' => 'nullable|string|max:255',
                'gender' => 'nullable|string|max:255',
                'position' => 'nullable|string|max:255',
            ]);

            $validator->after(function ($validator) use ($data, $actor, $canManageAccess) {
                // K-ORG-01: บทบาทในไฟล์ต้องไม่เกินของผู้นำเข้า (ยกเว้นผู้มีสิทธิ์กำหนดสิทธิ์)
                $role = filled($data['role_id'] ?? null) ? Role::find($data['role_id']) : null;
                if (! $canManageAccess && $role && ! $this->roleWithinActor($actor, $role)) {
                    $validator->errors()->add('role_id', self::DENY_ROLE);
                }

                if (filled($data['id_card_number'] ?? null) && UserProfile::where(
                    'id_card_number_hash',
                    hash('sha256', $data['id_card_number'].config('app.key'))
                )->exists()) {
                    $validator->errors()->add('id_card_number', 'เลขบัตรประชาชนนี้มีในระบบแล้ว');
                }
            });

            if ($validator->fails()) {
                $errors[] = "บรรทัดที่ {$line}: ".implode(' ', $validator->errors()->all());

                continue;
            }

            $valid = $validator->validated();

            $importedIds[] = DB::transaction(function () use ($valid, $profileColumns) {
                $user = User::create([
                    'name' => $valid['name'],
                    'email' => $valid['email'],
                    // phone อยู่บนตาราง users (เข้ารหัสด้วย cast) ไม่ใช่ user_profiles
                    'phone' => $valid['phone'] ?? null,
                    // รหัสผ่านสุ่มที่ไม่มีใครรู้ — เจ้าตัวต้องตั้งเองผ่าน "ลืมรหัสผ่าน"
                    'password' => bcrypt(Str::password(24)),
                    'department_id' => $valid['department_id'],
                    // ต้องมีประเภทบุคลากรเสมอ ไม่งั้นนโยบายสิทธิการลาจะหาไม่เจอ
                    'personnel_type_id' => ($valid['personnel_type_id'] ?? null)
                        ?: PersonnelType::where('code', 'civil_servant')->value('id'),
                ]);

                if ($role = Role::find($valid['role_id'])) {
                    $user->syncRoles([$role->name]);
                }

                // id_card_number เข้ารหัสด้วย cast และ UserProfile::saving() เติม id_card_number_hash ให้
                $user->profile()->create(array_filter(
                    array_intersect_key($valid, array_flip($profileColumns)),
                    fn ($value) => filled($value)
                ));

                $this->provisionLeaveBalances($user);

                return $user->id;
            });
        }

        fclose($handle);

        app(AuditLogger::class)->log('user.imported', 'user', null, after: [
            'imported' => count($importedIds),
            'rejected' => count($errors),
            'user_ids' => $importedIds,
        ]);

        return response()->json([
            'message' => 'นำเข้าผู้ใช้สำเร็จ '.count($importedIds).' รายการ'
                .($errors === [] ? '' : ' · ข้าม '.count($errors).' รายการ')
                .' — ผู้ใช้ใหม่ต้องตั้งรหัสผ่านเองผ่านเมนู "ลืมรหัสผ่าน"',
            'imported' => count($importedIds),
            'errors' => $errors,
        ]);
    }

    public function show(Request $request, User $user)
    {
        app(PersonalDataAccessLogger::class)
            ->record($request, $user->id, 'user_profile', 'เปิดดูทะเบียนประวัติบุคลากร');

        return response()->json($user->load(['roles', 'permissions:id,name', 'department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'currentAssignment.positionRef.group', 'orgUnit', 'personnelType']));
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
            'position_uuid' => 'nullable|uuid',
            'profile' => 'nullable|array',
            'custom_permissions' => 'nullable',
        ]);

        $position = $this->requestedPosition($request, $user);

        /*
         * K-ORG-01: ผู้ถือ `edit_users` / `system.user.manage` ที่ไม่มี `admin.users.manage_permissions`
         * แก้ข้อมูลได้แต่ห้ามเปลี่ยนบทบาทหรือสิทธิ์รายบุคคล (รวมบัญชีตนเอง)
         * ฟอร์มส่งค่าเดิมกลับมาทุกครั้ง จึงยอมรับค่าที่ไม่เปลี่ยนและข้ามการ sync
         */
        $actor = $request->user();
        $canManageAccess = $this->canManageAccess($actor);
        $perms = $request->has('custom_permissions') ? $this->requestedPermissions($request) : null;

        // ผู้ใช้ที่ยังไม่มีบทบาท (ข้อมูลเก่า) ให้บทบาทได้ตามกฎเดียวกับการสร้างผู้ใช้
        $assignFirstRole = false;

        if (! $canManageAccess) {
            if ($user->role_id === null) {
                $this->ensureRoleWithinActor($actor, Role::find($request->role_id));
                $assignFirstRole = true;
            } else {
                abort_if((int) $request->role_id !== (int) $user->role_id, 403, self::DENY_ROLE_CHANGE);
            }
            abort_if(
                $perms !== null && $this->sortedNames($perms) !== $this->sortedNames($user->getDirectPermissions()->pluck('name')->all()),
                403,
                self::DENY_PERMISSIONS
            );

            // เปลี่ยนรหัสผ่าน/อีเมลของผู้ที่มีสิทธิ์สูงกว่า = ยึดบัญชีนั้นได้ จึงห้ามเช่นกัน
            $credentialsChanged = $request->filled('password') || $request->email !== $user->email;
            abort_if($credentialsChanged && ! $this->accessWithinActor($actor, $user), 403, self::DENY_CREDENTIALS);
        }

        $accessBefore = $this->accessSnapshot($user);

        $user->name = $request->name;
        $user->email = $request->email;
        // role_id เป็น accessor ที่อ่านมาจาก Spatie ไม่ใช่คอลัมน์จริง — บทบาทกำหนดผ่าน syncRoles() ด้านล่าง
        $user->department_id = $request->department_id;
        $user->telegram_chat_id = $request->telegram_chat_id;

        if ($request->filled('password')) {
            $user->password = bcrypt($request->password);
        }

        if ($canManageAccess || $assignFirstRole) {
            $role = Role::find($request->role_id);
            if ($role) {
                $user->syncRoles([$role->name]);
            }
        }

        if ($canManageAccess && $perms !== null) {
            // LEGACY-04 (04-6): แหล่งสิทธิ์รายบุคคลมีที่เดียวคือ Spatie
            $user->syncPermissions($perms);
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

        if ($request->exists('position_uuid')) {
            app(PersonnelSyncService::class)->assignCatalogPosition($user->fresh(), $position);
            $user->unsetRelation('currentAssignment');
        }

        return response()->json([
            'message' => 'อัปเดตข้อมูลผู้ใช้เรียบร้อยแล้ว',
            'user' => $user->load(['roles', 'department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'currentAssignment.positionRef.group', 'orgUnit', 'personnelType']),
        ]);
    }

    /**
     * สร้างสิทธิ์วันลาปีปัจจุบันจาก "นโยบายสิทธิการลา" ตามประเภทบุคลากร
     *
     * เดิมอ่านจาก `leave_types.max_days` ทำให้ได้ตัวเลขคนละชุดกับที่ระบบใช้ตัดสิทธิ์
     * ใช้ร่วมกันระหว่าง store() และ importCsv()
     */
    private function provisionLeaveBalances(User $user): void
    {
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

        foreach (LeaveType::all() as $leaveType) {
            $policy = $activePolicies->get($leaveType->id)?->first();

            LeaveBalance::firstOrCreate(
                ['user_id' => $user->id, 'leave_type_id' => $leaveType->id, 'year' => $year],
                ['quota' => $policy?->annual_quota ?? 0, 'used' => 0, 'reserved' => 0, 'carried_forward' => 0, 'leave_policy_id' => $policy?->id]
            );
        }
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
            'user' => $user->fresh()->load(['roles', 'department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'currentAssignment.positionRef.group', 'orgUnit', 'personnelType']),
        ]);
    }

    /**
     * ตำแหน่งจากทะเบียนที่ฟอร์มส่งมา (ORG-ACCESS-02)
     *
     * ต้องเป็นตำแหน่งที่ใช้งานอยู่ ยกเว้นตำแหน่งเดิมของผู้ใช้ที่ถูกปิดใช้งานภายหลัง (บันทึกซ้ำได้โดยไม่ต้องเปลี่ยน)
     */
    private function requestedPosition(Request $request, ?User $user): ?Position
    {
        $uuid = $request->input('position_uuid');
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $currentId = $user?->currentAssignment?->position_id;

        return Position::assignable($uuid, $currentId === null ? null : (int) $currentId)
            ?? throw ValidationException::withMessages(['position_uuid' => 'ไม่พบตำแหน่งที่ใช้งานอยู่ในทะเบียนตำแหน่ง']);
    }

    /**
     * K-ORG-01 — ผู้ที่เปลี่ยนบทบาท/สิทธิ์รายบุคคลได้อย่างอิสระ
     *
     * เป็นสิทธิ์เดียวกับหน้ากำหนดสิทธิ์รายบุคคล (LEGACY-04) Super Admin ผ่านด้วย Gate::before
     */
    private function canManageAccess(User $actor): bool
    {
        return $actor->can('admin.users.manage_permissions');
    }

    private function ensureRoleWithinActor(User $actor, ?Role $role): void
    {
        abort_if($role && ! $this->roleWithinActor($actor, $role), 403, self::DENY_ROLE);
    }

    /**
     * บทบาท "ไม่เกิน" ผู้กระทำ = ไม่ใช่ Super Admin และทุก permission ของบทบาทผู้กระทำถืออยู่แล้ว
     * (Super Admin ไม่ได้ถือ permission ครบในตาราง — ได้ทุกอย่างผ่าน Gate::before จึงต้องกันด้วยชื่อ)
     */
    private function roleWithinActor(User $actor, Role $role): bool
    {
        if (in_array($role->name, self::SUPER_ADMIN_ROLES, true)) {
            return false;
        }

        return $role->permissions->pluck('name')->diff($actor->getAllPermissions()->pluck('name'))->isEmpty();
    }

    /** สิทธิ์รวมของ $target ไม่เกินของผู้กระทำ — ใช้ตัดสินว่าแตะข้อมูลเข้าระบบของเขาได้หรือไม่ */
    private function accessWithinActor(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return true;
        }

        if ($target->roles->pluck('name')->intersect(self::SUPER_ADMIN_ROLES)->isNotEmpty()) {
            return false;
        }

        return $target->getAllPermissions()->pluck('name')->diff($actor->getAllPermissions()->pluck('name'))->isEmpty();
    }

    /** custom_permissions จากฟอร์ม (JSON string หรือ array) — null เมื่ออ่านไม่ได้ */
    private function requestedPermissions(Request $request): ?array
    {
        $perms = $request->custom_permissions;
        $perms = is_string($perms) ? json_decode($perms, true) : $perms;

        return is_array($perms) ? $perms : null;
    }

    private function sortedNames(array $names): array
    {
        $names = array_values(array_unique(array_map('strval', $names)));
        sort($names);

        return $names;
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
