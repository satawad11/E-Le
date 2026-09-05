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
use App\Services\Files\ImageUploadService;
use App\Services\LeavePolicyService;
use App\Services\OrgScopeResolver;
use App\Services\PermissionEvaluator;
use App\Services\PersonalDataAccessLogger;
use App\Services\SystemRoleGuard;
use App\Support\UploadRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * ทะเบียนบุคลากรทั้งหมด
     *
     * SEC-030 — endpoint แบบรายการไม่ส่งเลขประจำตัวประชาชนและเลขหนังสือเดินทาง
     * ฉบับเต็มออกไปอีกต่อไป
     *
     * เดิมหน้าจัดการผู้ใช้ดึงเลขบัตรประชาชนเต็มของบุคลากร "ทุกคน" มาพร้อมกัน
     * ในคำขอเดียว แล้วแสดงเป็นคอลัมน์ในตาราง · ใครก็ตามที่เปิดหน้านี้ได้
     * (หรือดักอ่าน response ได้) จึงได้ฐานเลขบัตรประชาชนทั้งสถานศึกษาไปในคราวเดียว
     *
     * รายการส่งเฉพาะเลขแบบปิดบัง (4 หลักท้าย) ซึ่งพอสำหรับการยืนยันตัวบุคคลบนตาราง
     * ส่วนเลขเต็มเปิดได้ที่ `show()` ทีละคน ซึ่งตรวจสิทธิ์ ขอบเขต และบันทึกการเข้าถึง
     *
     * การค้นหาด้วยเลขเต็มยังทำได้ผ่านพารามิเตอร์ `id_card` ซึ่งเทียบด้วยค่าแฮช
     * จึงไม่ต้องส่งเลขของใครออกไปเลย
     */
    public function index(Request $request)
    {
        $query = User::with(['roles', 'department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'orgUnit', 'personnelType'])
            ->orderBy('name', 'asc');

        $idCard = preg_replace('/\D/', '', (string) $request->query('id_card', ''));

        if ($idCard !== '') {
            $hash = hash('sha256', $idCard.config('app.key'));
            $query->whereHas('profile', fn ($profile) => $profile->where('id_card_number_hash', $hash));
        }

        $users = $query->get();

        // คืนทะเบียนประวัติของบุคลากรทุกคน จึงถือเป็นการเข้าถึงข้อมูลส่วนบุคคลแบบรวม
        app(PersonalDataAccessLogger::class)
            ->recordBulk($request, 'user_directory', $users->count(), 'เปิดหน้าจัดการผู้ใช้งาน (ทะเบียนประวัติบุคลากรทั้งหมด)');

        return response()->json($users->map(fn (User $user) => $this->withMaskedIdentifiers($user)));
    }

    /**
     * SEC-030 — เลขประจำตัวประชาชนที่ถูกส่งเข้ามาต้องเป็นเลขจริงเท่านั้น
     *
     * รายการส่งเลขออกไปแบบปิดบัง ถ้า client ส่งค่าที่ปิดบังนั้นกลับเข้ามาโดยไม่ตั้งใจ
     * (เช่นเอาค่าจากตารางไปใส่ฟอร์มแล้วกดบันทึก) ข้อมูลจริงจะถูกเขียนทับด้วย `x`
     * และกู้คืนไม่ได้ เพราะคอลัมน์นี้เข้ารหัสไว้และไม่มีสำเนาอื่น
     *
     * @param  array<string, mixed>  $profileData
     */
    private function assertIdCardIsNotMasked(array $profileData): void
    {
        $idCard = $profileData['id_card_number'] ?? null;

        if ($idCard === null || $idCard === '') {
            return;
        }

        if (preg_match('/^\d{13}$/', preg_replace('/\D/', '', (string) $idCard) ?? '') !== 1
            || str_contains((string) $idCard, 'x')) {
            throw ValidationException::withMessages([
                'profile.id_card_number' => ['เลขประจำตัวประชาชนต้องเป็นตัวเลข 13 หลัก กรุณาเปิดทะเบียนประวัติของบุคลากรรายนี้แล้วกรอกใหม่'],
            ]);
        }
    }

    /**
     * ปิดบังตัวระบุที่อ่อนไหวก่อนส่งออกจาก endpoint แบบรายการ
     *
     * แทนที่ค่าในผลลัพธ์ที่ serialize แล้ว ไม่ได้แก้ระเบียนในฐานข้อมูล
     *
     * @return array<string, mixed>
     */
    private function withMaskedIdentifiers(User $user): array
    {
        $payload = $user->toArray();

        if (isset($payload['profile']) && is_array($payload['profile'])) {
            $payload['profile']['id_card_number'] = $user->profile?->maskedIdCard();
            $payload['profile']['id_card_last_four'] = $user->profile?->idCardLastFour();
            unset($payload['profile']['id_card_number_hash'], $payload['profile']['passport_number']);
        }

        return $payload;
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
            // SEC-028 — เดิมช่องนี้ไม่มีกฎ validate ใด ๆ เลย อัปโหลดไฟล์ชนิดใดขนาดใดก็ได้
            'profile_photo' => array_merge(['nullable'], UploadRules::profilePhoto()),
            'custom_permissions' => 'nullable',
        ]);

        $profileData = $request->has('profile') ? (is_string($request->profile) ? json_decode($request->profile, true) : $request->profile) : [];
        $this->assertIdCardIsNotMasked((array) $profileData);
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
                $path = app(ImageUploadService::class)->storeUploadedFile($request->file('profile_photo'), 'profile_photos', 'profile_photos', 'profile_photo');
                $profileData['profile_photo_path'] = $path;
            }
            $user->profile()->create($profileData);

            $this->provisionLeaveBalances($user);

            return $user;
        });

        return response()->json([
            'message' => 'สร้างผู้ใช้เรียบร้อยแล้ว',
            'user' => $user->load(['roles', 'department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'orgUnit', 'personnelType']),
        ], 201);
    }

    /**
     * นำเข้าผู้ใช้จากไฟล์ CSV
     *
     * CLEANUP-01 — หน้าจัดการผู้ใช้มีปุ่มนำเข้ามาตลอด แต่ route ชี้ไปยังเมธอดที่ไม่มีอยู่
     * การนำเข้าจึงล้มเหลวทุกครั้ง
     *
     * ข้อกำหนดด้านความปลอดภัยของการนำเข้าชุดนี้:
     *   - ไม่รับรหัสผ่านจากไฟล์ ระบบสุ่มรหัสผ่านที่ใช้ครั้งเดียวให้ และไม่ส่งกลับ
     *     ผู้ใช้ใหม่ต้องตั้งรหัสผ่านเองผ่าน "ลืมรหัสผ่าน"
     *   - แถวใดผิดพลาดให้ข้ามแถวนั้นและรายงานเลขบรรทัด ไม่ล้มทั้งไฟล์
     *   - บทบาทที่กำหนดได้เท่ากับที่หน้าสร้างผู้ใช้ทีละคนกำหนดได้ (สิทธิ์เดียวกัน)
     *     จึงไม่เปิดช่องยกระดับสิทธิ์ใหม่
     */
    public function importCsv(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        abort_if($handle === false, 422, 'เปิดไฟล์ที่อัปโหลดไม่ได้');

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            throw ValidationException::withMessages(['file' => ['ไฟล์ว่างเปล่า']]);
        }

        // ตัด BOM ของ Excel ออกจากหัวคอลัมน์แรก ไม่งั้นชื่อคอลัมน์แรกจะไม่ตรงตลอดกาล
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(fn ($column) => trim((string) $column), $header);

        $missing = array_diff(['name', 'email', 'role_id', 'department_id'], $header);
        if ($missing !== []) {
            fclose($handle);

            throw ValidationException::withMessages([
                'file' => ['ไฟล์ขาดคอลัมน์ที่จำเป็น: '.implode(', ', $missing)],
            ]);
        }

        $profileColumns = ['id_card_number', 'title', 'first_name', 'last_name', 'gender', 'phone', 'position'];
        $imported = 0;
        $errors = [];
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if ($row === [null] || $row === []) {
                continue;
            }

            $data = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));

            $validator = Validator::make($data, [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email',
                'role_id' => 'required|exists:roles,id',
                'department_id' => 'required|exists:departments,id',
                'personnel_type_id' => 'nullable|exists:personnel_types,id',
            ]);

            if ($validator->fails()) {
                $errors[] = "บรรทัดที่ {$line}: ".implode(' ', $validator->errors()->all());

                continue;
            }

            $valid = $validator->validated();

            DB::transaction(function () use ($valid, $data, $profileColumns) {
                $user = User::create([
                    'name' => $valid['name'],
                    'email' => $valid['email'],
                    // รหัสผ่านสุ่มที่ไม่มีใครรู้ — เจ้าตัวต้องตั้งเองผ่าน "ลืมรหัสผ่าน"
                    'password' => bcrypt(Str::password(24)),
                    'department_id' => $valid['department_id'],
                    'personnel_type_id' => $valid['personnel_type_id']
                        ?? PersonnelType::where('code', 'civil_servant')->value('id'),
                ]);

                if ($role = Role::find($valid['role_id'])) {
                    $user->syncRoles([$role->name]);
                }

                $profile = array_filter(
                    array_intersect_key($data, array_flip($profileColumns)),
                    fn ($value) => filled($value)
                );
                if ($profile !== []) {
                    $user->profile()->create($profile);
                }

                $this->provisionLeaveBalances($user);
            });

            $imported++;
        }

        fclose($handle);

        app(AuditLogger::class)->log('user.imported', 'user', null, after: [
            'imported' => $imported,
            'rejected' => count($errors),
        ]);

        return response()->json([
            'message' => "นำเข้าผู้ใช้สำเร็จ {$imported} รายการ"
                .($errors === [] ? '' : ' · ข้าม '.count($errors).' รายการ')
                .' — ผู้ใช้ใหม่ต้องตั้งรหัสผ่านเองผ่านเมนู "ลืมรหัสผ่าน"',
            'imported' => $imported,
            'errors' => $errors,
        ]);
    }

    public function show(Request $request, User $user)
    {
        /*
         * SEC-030 — หน้ารายละเอียดเปิดเลขประจำตัวประชาชนฉบับเต็ม จึงต้องตรวจขอบเขต
         * หน่วยงานด้วย ไม่ใช่แค่ว่ามีสิทธิ์เปิดหน้านี้หรือไม่
         *
         * ผู้ดูแลที่ถูกจำกัดขอบเขตไว้ที่หน่วยงานของตน (`permission_scopes`) ต้องเปิด
         * ทะเบียนประวัติของหน่วยงานอื่นไม่ได้ · ถ้าไม่ได้กำหนดขอบเขตไว้ = ไม่จำกัด
         * ตามพฤติกรรมเดิม
         */
        $viewer = $request->user();
        $ability = app(PermissionEvaluator::class)->allows($viewer, 'system.user.manage')
            ? 'system.user.manage'
            : 'system.user.view';

        abort_unless(
            $viewer->is($user) || app(OrgScopeResolver::class)->canSeeOrgUnit(
                $viewer, $ability, $user->org_unit_id, OrgScopeResolver::ORGANIZATION
            ),
            403,
            'ไม่มีสิทธิ์เปิดทะเบียนประวัติของบุคลากรนอกขอบเขตที่รับผิดชอบ',
        );

        app(PersonalDataAccessLogger::class)
            ->record($request, $user->id, 'user_profile', 'เปิดดูทะเบียนประวัติบุคลากร (รวมเลขประจำตัวประชาชนฉบับเต็ม)');

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
            // SEC-028 — เดิมช่องนี้ไม่มีกฎ validate ใด ๆ เลย อัปโหลดไฟล์ชนิดใดขนาดใดก็ได้
            'profile_photo' => array_merge(['nullable'], UploadRules::profilePhoto()),
            'custom_permissions' => 'nullable',
        ]);

        /*
         * SEC-026 — กัน self-lockout และการถอดผู้ดูแลระบบสูงสุดคนสุดท้าย
         *
         * ตรวจก่อนแตะข้อมูลใด ๆ เพื่อไม่ให้เกิดสภาพแก้ไปแล้วครึ่งทาง
         */
        $guard = app(SystemRoleGuard::class);
        if ($guard->roleChangeWouldRemoveLastSuperAdmin($user, (int) $request->role_id)) {
            return response()->json(['message' => $guard->blockedMessage()], 422);
        }

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
            $this->assertIdCardIsNotMasked((array) $profileData);
            if ($request->hasFile('profile_photo')) {
                $path = app(ImageUploadService::class)->storeUploadedFile($request->file('profile_photo'), 'profile_photos', 'profile_photos', 'profile_photo');
                $profileData['profile_photo_path'] = $path;
            }
            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                $profileData
            );
        } elseif ($request->hasFile('profile_photo')) {
            $path = app(ImageUploadService::class)->storeUploadedFile($request->file('profile_photo'), 'profile_photos', 'profile_photos', 'profile_photo');
            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                ['profile_photo_path' => $path]
            );
        }

        return response()->json([
            'message' => 'อัปเดตข้อมูลผู้ใช้เรียบร้อยแล้ว',
            'user' => $user->load(['roles', 'department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'orgUnit', 'personnelType']),
        ]);
    }

    /**
     * สร้างสิทธิ์วันลาปีปัจจุบันจาก "นโยบายสิทธิการลา" ตามประเภทบุคลากร
     *
     * เดิมอ่านจาก `leave_types.max_days` ทำให้ได้ตัวเลขคนละชุดกับที่ระบบใช้ตัดสิทธิ์
     * และตรรกะชุดนี้เคยเขียนซ้ำอยู่ใน store() เท่านั้น การนำเข้า CSV จึงต้องใช้ร่วมกัน
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

    public function destroy(User $user)
    {
        // SEC-026 — ลบผู้ดูแลระบบสูงสุดคนสุดท้ายไม่ได้ ระบบจะไม่เหลือใครแก้กลับ
        $guard = app(SystemRoleGuard::class);
        if ($guard->isLastActiveSuperAdmin($user)) {
            return response()->json(['message' => $guard->blockedMessage()], 422);
        }

        $user->delete();

        return response()->json(['message' => 'ลบผู้ใช้เรียบร้อยแล้ว']);
    }

    public function toggleStatus(User $user)
    {
        // SEC-026 — ระงับการใช้งานผู้ดูแลระบบสูงสุดคนสุดท้ายไม่ได้ด้วยเหตุผลเดียวกัน
        $guard = app(SystemRoleGuard::class);
        if ($user->is_active && $guard->isLastActiveSuperAdmin($user)) {
            return response()->json(['message' => $guard->blockedMessage()], 422);
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        return response()->json([
            'message' => $user->is_active ? 'เปิดใช้งานผู้ใช้แล้ว' : 'ระงับการใช้งานผู้ใช้แล้ว',
            'user' => $user->fresh()->load(['roles', 'department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'orgUnit', 'personnelType']),
        ]);
    }
}
