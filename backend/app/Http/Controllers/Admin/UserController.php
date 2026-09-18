<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Files\ImageUploadService;
use App\Services\LeavePolicyService;
use App\Services\OrgScopeResolver;
use App\Services\PermissionEvaluator;
use App\Services\PersonalDataAccessLogger;
use App\Services\SystemRoleGuard;
use App\Support\UploadRules;
use Illuminate\Database\Eloquent\Builder;
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
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'org_unit_id' => ['nullable', 'integer', 'exists:org_units,id'],
            'status' => ['nullable', 'string', 'max:50'],
            'id_card' => ['nullable', 'string', 'max:32'],
            'trashed' => ['nullable', 'in:only'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = User::with($this->userRelations())->orderBy('name', 'asc');

        $this->applyDirectoryScope($query, $request);

        /*
         * `trashed=only` — บัญชีที่ถูกลบ
         *
         * การลบเป็นแบบ soft เพื่อไม่ให้ประวัติการลาหายไปด้วย ถ้าไม่มีทางเห็นบัญชี
         * ที่ถูกลบเลย ผู้ดูแลก็กู้คืนไม่ได้และไม่รู้ว่าเคยลบใครไปบ้าง
         */
        if (($data['trashed'] ?? null) === 'only') {
            $query->onlyTrashed();
        }

        $idCard = preg_replace('/\D/', '', (string) ($data['id_card'] ?? ''));

        if ($idCard !== '') {
            $hash = hash('sha256', $idCard.config('app.key'));
            $query->whereHas('profile', fn ($profile) => $profile->where('id_card_number_hash', $hash));
        }

        $this->applyDirectoryFilters($query, $data);

        /*
         * แบ่งหน้าเสมอ
         *
         * เดิม endpoint นี้คืนบุคลากร **ทุกคน** พร้อมความสัมพันธ์ครบชุดในคำขอเดียว
         * แล้วให้หน้าจอค้นหาและกรองเอง — ทะเบียนประวัติทั้งสถานศึกษาจึงถูกส่งออกไป
         * ทุกครั้งที่เปิดหน้า ทั้งที่ผู้ใช้ดูจริงแค่ไม่กี่แถว
         */
        $users = $query->paginate(min((int) ($data['per_page'] ?? 25), 100))->withQueryString();

        // ยังเป็นการเข้าถึงข้อมูลส่วนบุคคลแบบรวม — บันทึกตามจำนวนที่ส่งออกไปจริง
        app(PersonalDataAccessLogger::class)
            ->recordBulk($request, 'user_directory', $users->count(), 'เปิดหน้าจัดการผู้ใช้งาน (ทะเบียนประวัติบุคลากร)');

        $users->getCollection()->transform(fn (User $user) => $this->withMaskedIdentifiers($user));

        return response()->json($users);
    }

    /**
     * จำกัดทะเบียนบุคลากรตามขอบเขตของผู้เรียก
     *
     * **ที่มา:** หน้ารายละเอียด (`show()`) ตรวจขอบเขตหน่วยงานมาตลอด แต่หน้ารายการ
     * ไม่เคยตรวจเลย — ผู้ดูแลที่ถูกจำกัดขอบเขตไว้จึงเห็นรายชื่อบุคลากรทั้งองค์กร
     * แต่กดเปิดแล้วได้ 403 · ใช้กติกาเดียวกับ `show()` ทั้งสองที่จึงตรงกัน
     *
     * ไม่ได้กำหนดขอบเขตไว้ = ไม่จำกัด ตามพฤติกรรมเดิม
     *
     * @param  Builder<User>  $query
     */
    private function applyDirectoryScope($query, Request $request): void
    {
        $viewer = $request->user();
        $ability = app(PermissionEvaluator::class)->allows($viewer, 'system.user.manage')
            ? 'system.user.manage'
            : 'system.user.view';

        $visible = app(OrgScopeResolver::class)
            ->visibleOrgUnitIds($viewer, $ability, OrgScopeResolver::ORGANIZATION);

        if ($visible === null) {
            return;
        }

        // ไม่เห็นหน่วยงานใดเลย — เหลือเฉพาะทะเบียนของตนเอง
        if ($visible === []) {
            $query->whereKey($viewer->getKey());

            return;
        }

        // เห็นทะเบียนของตนเองเสมอ แม้จะย้ายออกนอกขอบเขตที่รับผิดชอบไปแล้ว
        $query->where(fn ($scoped) => $scoped
            ->whereIn('org_unit_id', $visible)
            ->orWhere('id', $viewer->getKey()));
    }

    /**
     * ค้นหาและกรองฝั่งเซิร์ฟเวอร์
     *
     * เดิมหน้าจอกรองจากข้อมูลทั้งก้อนที่โหลดมาแล้ว การแบ่งหน้าจึงเป็นไปไม่ได้
     *
     * @param  Builder<User>  $query
     * @param  array<string,mixed>  $data
     */
    private function applyDirectoryFilters($query, array $data): void
    {
        if (($data['org_unit_id'] ?? null) !== null) {
            $query->where('org_unit_id', $data['org_unit_id']);
        }

        if (($status = $data['status'] ?? null) !== null && $status !== '') {
            /*
             * สถานะว่าง = "ปฏิบัติหน้าที่" ตามที่หน้าจอแสดงมาตลอด จึงต้องนับรวม
             * แถวที่ยังไม่เคยกรอกสถานะไว้ด้วย ไม่งั้นตัวกรองจะทำให้คนหายไปเฉย ๆ
             */
            $query->whereHas('profile', function ($profile) use ($status) {
                $profile->where('status', $status);

                if ($status === 'ปฏิบัติหน้าที่') {
                    $profile->orWhereNull('status');
                }
            });
        }

        if (($search = trim((string) ($data['search'] ?? ''))) === '') {
            return;
        }

        /*
         * ค้นจากชื่อและรหัสบุคลากรเท่านั้น
         *
         * `id_card_number` เข้ารหัสไว้ในฐานข้อมูล การ LIKE บนไซเฟอร์เท็กซ์จึงไม่มีทาง
         * ตรงกับอะไรเลย · การค้นด้วยเลขบัตรทำผ่านพารามิเตอร์ `id_card` ซึ่งเทียบด้วย
         * ค่าแฮชของเลขเต็ม 13 หลัก และไม่ต้องส่งเลขของใครออกไปจากเซิร์ฟเวอร์
         */
        $query->where(function ($match) use ($search) {
            $match->where('name', 'like', '%'.$search.'%')
                ->orWhereHas('personnel', fn ($p) => $p->where('personnel_code', 'like', '%'.$search.'%'))
                ->orWhereHas('profile', fn ($p) => $p->where('personnel_code', 'like', '%'.$search.'%'));
        });
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
        $users = User::select('id', 'uuid', 'name', 'org_unit_id')->with('personnel:id,user_id,uuid,personnel_code')->orderBy('name', 'asc')->get();

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:12',
            'role_id' => 'required|exists:roles,id',
            'org_unit_id' => 'required|exists:org_units,id',
            'telegram_chat_id' => 'nullable|string|max:255',
            /*
             * ประเภทบุคลากรเป็นตัวตัดสินสิทธิวันลาทั้งปี (`leave_policies` แยกตามประเภท)
             * เดิมช่องนี้ไม่บังคับและระบบเดาเป็น "ข้าราชการ" ให้เงียบ ๆ ทุกครั้ง —
             * บุคลากรที่ไม่ใช่ข้าราชการจึงได้โควตาผิดตั้งแต่วันแรกโดยไม่มีอะไรบอก
             */
            'personnel_type_id' => 'required|exists:personnel_types,id',
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
                'org_unit_id' => $request->org_unit_id,
                'telegram_chat_id' => $request->telegram_chat_id,
                'personnel_type_id' => $request->personnel_type_id,
            ]);

            /*
             * รหัสที่ผู้ดูแลตั้งให้เป็นรหัสชั่วคราวเสมอ — บังคับให้เจ้าตัวเปลี่ยนก่อนใช้งาน
             * ไม่งั้นผู้ดูแลกับเจ้าของบัญชีจะรู้รหัสเดียวกันตลอดไป และ audit log
             * ก็ตอบไม่ได้ว่าใครเป็นคนทำรายการจริง
             */
            $user->forceFill(['must_change_password' => true])->save();

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

            if ($request->boolean('is_org_unit_head') && $user->org_unit_id) {
                DB::table('org_unit_managers')->insert([
                    'uuid' => (string) Str::uuid(),
                    'org_unit_id' => $user->org_unit_id,
                    'user_id' => $user->id,
                    'assignment_type' => 'MANAGER',
                    'scope' => 'UNIT',
                    'effective_from' => now()->toDateString(),
                    'effective_to' => null,
                    'note' => 'แต่งตั้งผ่านหน้าจัดการบุคลากร',
                    'created_by' => $request->user()?->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->provisionLeaveBalances($user);

            return $user;
        });

        return response()->json([
            'message' => 'สร้างผู้ใช้เรียบร้อยแล้ว',
            'user' => $user->load($this->userRelations()),
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

        $missing = array_diff(['name', 'email', 'role_id', 'org_unit_id', 'personnel_type_id'], $header);
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
                'org_unit_id' => 'required|exists:org_units,id',
                'personnel_type_id' => 'required|exists:personnel_types,id',
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
                    'org_unit_id' => $valid['org_unit_id'],
                    'personnel_type_id' => $valid['personnel_type_id'],
                ]);

                // ไม่มีใครรู้รหัสสุ่มนี้ — เจ้าตัวต้องตั้งใหม่ก่อนใช้งานเช่นเดียวกัน
                $user->forceFill(['must_change_password' => true])->save();

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

        return response()->json($user->load($this->userRelations()));
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
            'org_unit_id' => 'required|exists:org_units,id',
            /*
             * เดิมหน้านี้แก้ประเภทบุคลากรไม่ได้เลย ค่าที่ระบบเดาให้ตอนสร้างบัญชี
             * จึงติดอยู่ถาวร ทั้งที่มันเป็นตัวตัดสินสิทธิวันลาทั้งปี
             */
            'personnel_type_id' => 'required|exists:personnel_types,id',
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
        $user->org_unit_id = $request->org_unit_id;
        $user->personnel_type_id = $request->personnel_type_id;
        $user->telegram_chat_id = $request->telegram_chat_id;

        if ($request->filled('password')) {
            $user->password = bcrypt($request->password);
            // ผู้ดูแลรีเซ็ตรหัสให้ = รหัสชั่วคราว เจ้าตัวต้องตั้งใหม่ก่อนใช้งานต่อ
            $user->must_change_password = true;
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

        if ($request->has('is_org_unit_head')) {
            $isHead = $request->boolean('is_org_unit_head');
            $today = now()->toDateString();
            $currentManagerRow = DB::table('org_unit_managers')
                ->where('user_id', $user->id)
                ->where('org_unit_id', $user->org_unit_id)
                ->whereDate('effective_from', '<=', $today)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
                ->first();

            if ($isHead && ! $currentManagerRow && $user->org_unit_id) {
                DB::table('org_unit_managers')->insert([
                    'uuid' => (string) Str::uuid(),
                    'org_unit_id' => $user->org_unit_id,
                    'user_id' => $user->id,
                    'assignment_type' => 'MANAGER',
                    'scope' => 'UNIT',
                    'effective_from' => $today,
                    'effective_to' => null,
                    'note' => 'แต่งตั้งผ่านหน้าจัดการบุคลากร',
                    'created_by' => $request->user()?->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif (! $isHead && $currentManagerRow) {
                DB::table('org_unit_managers')
                    ->where('id', $currentManagerRow->id)
                    ->update(['effective_to' => $today, 'updated_at' => now()]);
            }
        }

        return response()->json([
            'message' => 'อัปเดตข้อมูลผู้ใช้เรียบร้อยแล้ว',
            'user' => $user->load($this->userRelations()),
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

    /**
     * ลบบัญชีผู้ใช้
     *
     * **ปฏิเสธเมื่อยังมีเอกสารอ้างถึง** — ทะเบียนการลาของราชการเป็นเอกสารที่ต้องเก็บ
     * ตามระเบียบ และแถวผู้อนุมัติบนใบลาของคนอื่นก็ผูกกับบัญชีนี้ การลบทั้งที่ยังมี
     * เอกสารอ้างอยู่ทำให้ใบลาของผู้อื่นอธิบายไม่ได้ว่าใครเป็นผู้อนุมัติ
     *
     * บัญชีที่ไม่มีเอกสารใดอ้างถึง (เช่นสร้างผิดแล้วยังไม่ได้ใช้งาน) ลบได้ และเป็น
     * การลบแบบ soft — แถวยังอยู่ CASCADE จึงไม่ทำงาน และกู้คืนได้ที่ `restore()`
     */
    public function destroy(User $user)
    {
        // SEC-026 — ลบผู้ดูแลระบบสูงสุดคนสุดท้ายไม่ได้ ระบบจะไม่เหลือใครแก้กลับ
        $guard = app(SystemRoleGuard::class);
        if ($guard->isLastActiveSuperAdmin($user)) {
            return response()->json(['message' => $guard->blockedMessage()], 422);
        }

        $blockers = $this->deletionBlockers($user);

        if ($blockers !== []) {
            return response()->json([
                'message' => 'ลบบัญชีนี้ไม่ได้เพราะยังมี '.implode(' · ', $blockers)
                    .' — ข้อมูลเหล่านี้เป็นเอกสารที่ต้องเก็บตามระเบียบ '
                    .'ให้ใช้ "ระงับการใช้งาน" แทน ผู้ใช้จะเข้าระบบไม่ได้แต่ประวัติยังอยู่ครบ',
                'blockers' => $blockers,
            ], 422);
        }

        $user->delete();

        return response()->json(['message' => 'ลบบัญชีผู้ใช้เรียบร้อยแล้ว']);
    }

    /**
     * กู้บัญชีที่ถูกลบกลับคืน
     *
     * การลบเป็นแบบ soft ข้อมูลจึงยังอยู่ครบ · บัญชีที่กู้กลับมาจะอยู่ในสถานะ
     * "ระงับการใช้งาน" เสมอ เพื่อให้ผู้ดูแลตัดสินใจอีกครั้งก่อนให้เข้าระบบได้จริง
     */
    public function restore(int $id)
    {
        $user = User::onlyTrashed()->findOrFail($id);

        $user->restore();
        $user->forceFill(['is_active' => false])->save();

        return response()->json([
            'message' => 'กู้บัญชีผู้ใช้คืนแล้ว — อยู่ในสถานะระงับการใช้งาน กรุณาเปิดใช้งานอีกครั้งเมื่อพร้อม',
            'user' => $user->fresh()->load($this->userRelations()),
        ]);
    }

    /**
     * ดำเนินการกับบุคลากรหลายคนพร้อมกัน
     *
     * **ที่มา:** งานจริงตอนต้นปีการศึกษาคือย้ายคนทั้งกลุ่มไปอีกหน่วยงาน หรือระงับ
     * บัญชีของผู้ที่พ้นหน้าที่พร้อมกันหลายคน · เดิมทำได้ทีละคนเท่านั้น ผู้ดูแลจึงต้อง
     * เปิด–แก้–บันทึกซ้ำหลายสิบรอบ ซึ่งนอกจากช้าแล้วยังพลาดง่าย
     *
     * ทุกการกระทำเดินผ่านด่านเดิมทั้งหมด — ขอบเขตหน่วยงานของผู้เรียก และด่านกัน
     * ถอดผู้ดูแลระบบสูงสุดคนสุดท้าย — ไม่ใช่ทางลัดที่ข้ามกติกาของการทำทีละคน
     */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', 'in:activate,suspend,move_org_unit'],
            'user_ids' => ['required', 'array', 'min:1', 'max:200'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'org_unit_id' => ['required_if:action,move_org_unit', 'nullable', 'integer', 'exists:org_units,id'],
        ], [
            'user_ids.required' => 'กรุณาเลือกบุคลากรอย่างน้อย 1 คน',
            'user_ids.max' => 'เลือกได้ครั้งละไม่เกิน 200 คน',
            'org_unit_id.required_if' => 'กรุณาเลือกหน่วยงานปลายทาง',
        ]);

        $scoped = User::query();
        $this->applyDirectoryScope($scoped, $request);
        $targets = $scoped->whereIn('id', $data['user_ids'])->get();

        $guard = app(SystemRoleGuard::class);
        $changed = 0;
        $skipped = [];

        foreach ($targets as $user) {
            // ตัวเองไม่ควรถูกกวาดไปกับการกระทำหมู่โดยไม่ตั้งใจ
            if ($user->is($request->user())) {
                $skipped[] = $user->name.' (บัญชีของท่านเอง)';

                continue;
            }

            if ($data['action'] === 'suspend' && $guard->isLastActiveSuperAdmin($user)) {
                $skipped[] = $user->name.' (ผู้ดูแลระบบสูงสุดคนสุดท้าย)';

                continue;
            }

            match ($data['action']) {
                'activate' => $user->forceFill(['is_active' => true])->save(),
                'suspend' => $user->forceFill(['is_active' => false])->save(),
                'move_org_unit' => $user->forceFill(['org_unit_id' => $data['org_unit_id']])->save(),
            };

            $changed++;
        }

        // คนที่เลือกมาแต่อยู่นอกขอบเขตของผู้เรียก จะไม่อยู่ใน $targets ตั้งแต่แรก
        $outOfScope = count($data['user_ids']) - $targets->count();

        app(AuditLogger::class)->log('user.bulk_updated', 'user', null, after: [
            'action' => $data['action'],
            'org_unit_id' => $data['org_unit_id'] ?? null,
            'requested' => count($data['user_ids']),
            'changed' => $changed,
            'skipped' => $skipped,
            'out_of_scope' => $outOfScope,
        ]);

        return response()->json([
            'message' => "ดำเนินการกับบุคลากร {$changed} คนเรียบร้อยแล้ว",
            'changed' => $changed,
            'skipped' => $skipped,
            'out_of_scope' => $outOfScope,
        ]);
    }

    /**
     * ความสัมพันธ์ที่หน้าจอจัดการผู้ใช้ต้องใช้เสมอ
     *
     * เดิมรายการชุดนี้ถูกคัดลอกไว้ 5 ที่ในคลาสนี้ การเพิ่ม/แก้ความสัมพันธ์จึงต้อง
     * ไล่แก้ทุกจุด และพลาดจุดใดจุดหนึ่งแล้วหน้าจอจะได้ข้อมูลไม่ครบเฉพาะบางเส้นทาง
     *
     * @return array<int,string>
     */
    private function userRelations(): array
    {
        return [
            'roles', 'orgUnit', 'profile', 'personnel',
            'currentAssignment.orgUnit', 'currentAssignment.personnelType',
            'personnelType',
        ];
    }

    /**
     * เอกสารที่อ้างถึงบัญชีนี้และจะหายไปถ้าลบแถวจริง
     *
     * @return array<int,string>
     */
    private function deletionBlockers(User $user): array
    {
        $counts = [
            'ใบลา' => Leave::withTrashed()->where('user_id', $user->id)->count(),
            'รายการลงเวลา' => DB::table('attendances')->where('user_id', $user->id)->count(),
            'การอนุมัติที่ลงนามไว้บนใบลาของผู้อื่น' => DB::table('leave_approvals')
                ->where('approver_id', $user->id)->count(),
            'การมอบหมายเป็นผู้รับผิดชอบหน่วยงาน' => DB::table('org_unit_managers')
                ->where('user_id', $user->id)->count(),
        ];

        return collect($counts)
            ->filter()
            ->map(fn (int $count, string $label) => "{$label} {$count} รายการ")
            ->values()
            ->all();
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
            'user' => $user->fresh()->load($this->userRelations()),
        ]);
    }
}
