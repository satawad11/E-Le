<?php

namespace Database\Seeders;

use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Faker\Factory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        /*
         * Seeder ระดับติดตั้ง — ทุกตัว idempotent (firstOrCreate/updateOrCreate)
         * รันซ้ำได้โดยไม่สร้างข้อมูลซ้ำและไม่ลบของเดิม
         *
         * ลำดับสำคัญ: สิทธิ์ต้องมาก่อนเมนู เพราะ MenuSeeder ผูก menu_permissions
         *
         * ที่ **จงใจไม่ใส่** ไว้ที่นี่:
         *   PurgeAndMigrateTo7StandardRolesSeeder  ลบ role และการผูกสิทธิ์ทิ้ง
         *                                          เป็น one-off migration
         *   UpdateUserNamesSeeder                  แก้ข้อมูลย้อนหลังครั้งเดียว
         *   MockDataSeeder / MockLeaveSeeder       ข้อมูลจำลองสำหรับ dev เท่านั้น
         * ทั้งหมดต้องเรียกด้วยมือเมื่อจำเป็น
         */
        $this->call([
            /*
             * CLEANUP-01 (D5) — สร้าง "ชื่อสิทธิ์" ทั้งหมดก่อนใครเสมอ
             *
             * ชื่อที่โค้ดบังคับใช้แต่ไม่เคยถูกสร้าง (`leave.management.manage`,
             * `system.role.manage`) ทำให้ endpoint และเมนูที่อ้างชื่อนั้นใช้ได้เฉพาะ
             * Super Admin การสร้างชื่อไม่ได้แจกสิทธิ์ให้ใคร จึงปลอดภัยเสมอ
             */
            PermissionCatalogSeeder::class,
            RolesAndPermissionsSeeder::class,
            FiscalYearSeeder::class,
            ThaiGovernmentHoliday2569Seeder::class,
            DocumentTemplateSeeder::class,
            // เดิมสี่ตัวนี้ไม่เคยถูกเรียกเลย ทำให้ติดตั้งใหม่ได้ระบบที่ใช้งานไม่ครบ:
            // ตาราง menus ว่างเปล่า และสิทธิ์ที่ route บังคับใช้จริงไม่ถูกสร้าง
            OperationalPermissionSeeder::class,
            ModulePermissionSeeder::class,
            MenuSeeder::class,
            CancelSettingSeeder::class,
        ]);

        $itDept = Department::firstOrCreate(
            ['name' => 'ฝ่ายสารสนเทศ'],
            ['description' => 'ฝ่ายเทคโนโลยีสารสนเทศ']
        );
        $hrDept = Department::firstOrCreate(
            ['name' => 'ฝ่ายทรัพยากรบุคคล'],
            ['description' => 'ฝ่ายบริหารทรัพยากรบุคคล']
        );

        $employeeRole = Role::firstOrCreate(['name' => 'Employee'], ['description' => 'ผู้ใช้งานทั่วไป']);
        $hrHeadRole = Role::firstOrCreate(['name' => 'HR Head'], ['description' => 'หัวหน้าบุคลากร']);
        $deputyRole = Role::firstOrCreate(['name' => 'Deputy Director'], ['description' => 'รองผู้อำนวยการ']);
        $directorRole = Role::firstOrCreate(['name' => 'Director'], ['description' => 'ผู้อำนวยการ']);
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin'], ['description' => 'ผู้ดูแลระบบสูงสุด']);
        $docOfficerRole = Role::firstOrCreate(['name' => 'Document Officer'], ['description' => 'เจ้าหน้าที่สารบัญ']);

        // Core Thai government leave types. The complete set is maintained by
        // NewLeaveTypesSeeder so fresh and existing installations stay aligned.
        $leaveTypes = [
            ['name' => 'ลาป่วย',      'description' => 'ลาป่วยด้วยโรคภัยไข้เจ็บ'],
            ['name' => 'ลากิจส่วนตัว', 'description' => 'ลากิจส่วนตัวที่จำเป็น'],
            ['name' => 'ลาพักผ่อน',   'description' => 'ลาพักผ่อนประจำปี'],
            ['name' => 'ลาคลอดบุตร',  'description' => 'ลาคลอดบุตร'],
        ];

        foreach ($leaveTypes as $type) {
            LeaveType::firstOrCreate(['name' => $type['name']], ['description' => $type['description']]);
        }
        $this->call([NewLeaveTypesSeeder::class, LeaveFormExtraFieldSeeder::class]);

        /*
         * บัญชีเริ่มต้น (SEC-028)
         *
         * เดิม seeder นี้สร้างบัญชี 6 ตัว รวมถึง Super Admin ด้วย **รหัสผ่านตายตัว**
         * (`1111111111111` ถึง `6666666666666`) ระบบที่ติดตั้งจริงจึงมีบัญชีผู้ดูแลระบบ
         * สูงสุดที่ใครก็เดารหัสผ่านได้ตั้งแต่วินาทีแรก — SEC-027 เอาค่าเหล่านี้ออกจาก
         * หน้าเข้าสู่ระบบไปแล้ว แต่ตัวบัญชียังถูกสร้างอยู่
         *
         * ตอนนี้:
         *   - นอก local/testing จะสร้างเฉพาะบัญชีผู้ดูแลระบบ **หนึ่งบัญชี** และ
         *     รหัสผ่านมาจาก `ADMIN_PASSWORD` ถ้าไม่ตั้งไว้จะสุ่มให้แล้วพิมพ์ครั้งเดียว
         *   - ชุดบัญชีทดลองครบทุกบทบาทสร้างเฉพาะบน local/testing หรือเมื่อสั่ง
         *     `SEED_DEMO_ACCOUNTS=true` อย่างชัดแจ้ง
         */
        $faker = Factory::create('th_TH');
        $withDemoAccounts = app()->environment(['local', 'testing'])
            || filter_var(env('SEED_DEMO_ACCOUNTS', false), FILTER_VALIDATE_BOOL);

        $superAdmin = $this->seedAdministrator($superAdminRole, $itDept, $faker, $withDemoAccounts);
        $userIds = [$superAdmin->id];

        if ($withDemoAccounts) {
            $demoAccounts = [
                ['director@example.com', 'สมศักดิ์ ผู้อำนวยการ', $directorRole, $hrDept, 'สมศักดิ์', 'ผู้อำนวยการ', 'ผู้อำนวยการ', '2222222222222'],
                ['deputy@example.com', 'สมชาย รองผู้อำนวยการ', $deputyRole, $hrDept, 'สมชาย', 'รองผู้อำนวยการ', 'รองผู้อำนวยการ', '3333333333333'],
                ['hrhead@example.com', 'สมปอง หัวหน้าบุคลากร', $hrHeadRole, $hrDept, 'สมปอง', 'หัวหน้าบุคลากร', 'หัวหน้าบุคลากร', '4444444444444'],
                ['employee@example.com', 'สมหญิง พนักงานทั่วไป', $employeeRole, $hrDept, 'สมหญิง', 'พนักงานทั่วไป', 'พนักงานทั่วไป', '5555555555555'],
                ['doc@example.com', 'สมเกียรติ เจ้าหน้าที่สารบัญ', $docOfficerRole, $itDept, 'สมเกียรติ', 'เจ้าหน้าที่สารบัญ', 'เจ้าหน้าที่สารบัญ', '6666666666666'],
            ];

            foreach ($demoAccounts as [$email, $name, $role, $department, $firstName, $lastName, $position, $idCard]) {
                $account = User::firstOrCreate(
                    ['email' => $email],
                    ['name' => $name, 'password' => bcrypt($idCard), 'department_id' => $department->id]
                );
                $account->assignRole($role);
                $this->seedProfile($account, $faker, $firstName, $lastName, $position, $idCard);
                $userIds[] = $account->id;
            }
        }

        // Seed leave balances for current year
        $currentYear = (int) date('Y');
        $quotas = [
            'ลาป่วย' => 60,
            'ลากิจส่วนตัว' => 45,
            'ลาพักผ่อน' => 10,
            'ลาคลอดบุตร' => 90,
            'ลาไปช่วยเหลือภริยาที่คลอดบุตร' => 15,
        ];

        foreach ($userIds as $userId) {
            foreach (LeaveType::all() as $lt) {
                LeaveBalance::firstOrCreate(
                    ['user_id' => $userId, 'leave_type_id' => $lt->id, 'year' => $currentYear],
                    ['quota' => $quotas[$lt->name] ?? ($lt->max_days ?? 0), 'used' => 0]
                );
            }
        }

        // Seed dynamic approval steps matching the default workflow
        ApprovalStep::firstOrCreate(
            ['step_order' => 1],
            ['role_id' => $hrHeadRole->id, 'name' => 'ตรวจสอบโดยหัวหน้าบุคลากร']
        );
        ApprovalStep::firstOrCreate(
            ['step_order' => 2],
            ['role_id' => $deputyRole->id, 'name' => 'พิจารณาโดยรองผู้อำนวยการ']
        );
        ApprovalStep::firstOrCreate(
            ['step_order' => 3],
            ['role_id' => $directorRole->id, 'name' => 'อนุมัติรอบสุดท้ายโดยผู้อำนวยการ']
        );

        // LEGACY-03: เติม org_units/department_org_unit_map/users.org_unit_id
        // migration ทำ backfill นี้ไว้แล้วแต่รันก่อน seeder จึงไม่เจอ departments
        // ต้องอยู่หลังทั้ง department และ user ถูกสร้างครบ
        $this->call([OrgUnitBackfillSeeder::class]);

        // LEGACY-06: ตารางเวลาทำงานเริ่มต้น — ต้องอยู่หลังผู้ใช้ถูกสร้างแล้ว
        // เพราะอ่าน users.work_start_time เพื่อแยกคนที่มีเวลาเฉพาะตัว
        $this->call([WorkScheduleSeeder::class]);

        // LEGACY-05: แปลงสายอนุมัติด้านบนเป็น Leave Workflow v1 (shadow mode)
        // ต้องอยู่ท้ายสุดเพราะอ่านจาก approval_steps ที่เพิ่งสร้าง
        $this->call([LeaveWorkflowSeeder::class]);

        // DOC-ENGINE: สิทธิ์ Field Dictionary และแหล่งข้อมูลของศูนย์จัดการเอกสาร
        // ต้องรันหลังประเภทการลาและแม่แบบเอกสาร เพราะสร้างกติกาผูกแบบฟอร์มจากทั้งสองอย่าง
        $this->call([DocumentEngineSeeder::class]);
    }

    /**
     * บัญชีผู้ดูแลระบบเริ่มต้น — บัญชีเดียวที่ระบบต้องมีเพื่อเข้าไปตั้งค่าครั้งแรก
     *
     * SEC-028 — รหัสผ่านไม่ถูกฝังไว้ในโค้ดอีกต่อไป
     *   local/testing  ใช้รหัสเดิมเพื่อให้ชุดทดสอบและเครื่องพัฒนาทำงานได้เหมือนเดิม
     *   นอกจากนั้น    ใช้ `ADMIN_PASSWORD` ถ้ามี ไม่มีก็สุ่มให้แล้วพิมพ์ครั้งเดียวตอนติดตั้ง
     */
    private function seedAdministrator($superAdminRole, $department, $faker, bool $withDemoAccounts): User
    {
        $email = env('ADMIN_EMAIL', 'superadmin@example.com');
        $existing = User::where('email', $email)->first();

        if ($existing === null) {
            $password = $withDemoAccounts
                ? '1111111111111'
                : (env('ADMIN_PASSWORD') ?: Str::password(20));

            $existing = User::create([
                'email' => $email,
                'name' => 'ผู้ดูแลระบบ สูงสุด',
                'password' => bcrypt($password),
                'department_id' => $department->id,
            ]);

            if (! $withDemoAccounts && ! env('ADMIN_PASSWORD')) {
                $this->command?->warn('สร้างบัญชีผู้ดูแลระบบ '.$email.' ด้วยรหัสผ่านสุ่ม: '.$password);
                $this->command?->warn('บันทึกรหัสผ่านนี้ไว้ทันที ระบบจะไม่แสดงอีก และควรเปลี่ยนหลังเข้าสู่ระบบครั้งแรก');
            }
        }

        $existing->assignRole($superAdminRole);

        /*
         * บนระบบจริงผู้ดูแลระบบเป็นคนจริง จึงไม่ยัดเลขบัตรประชาชน วันเกิด และที่อยู่สมมติให้
         * — ข้อมูลปลอมในทะเบียนบุคลากรของราชการอันตรายกว่าการปล่อยว่างไว้ให้เจ้าตัวกรอกเอง
         */
        if ($withDemoAccounts) {
            $this->seedProfile($existing, $faker, 'ทดสอบ', 'ระบบ', 'ผู้ดูแลระบบ', '1111111111111');
        } elseif (! $existing->profile) {
            $existing->profile()->create([
                'title' => 'นาย',
                'first_name' => 'ผู้ดูแลระบบ',
                'last_name' => 'สูงสุด',
                'position' => 'ผู้ดูแลระบบ',
                'nationality' => 'ไทย',
                'status' => 'ปฏิบัติหน้าที่',
            ]);
        }

        return $existing;
    }

    private function seedProfile($user, $faker, $fname = 'ทดสอบ', $lname = 'ระบบ', $position = 'นักวิชาการ', $idCard = null)
    {
        $user->phone = $faker->numerify('08########');
        $user->save();

        if (! $user->profile) {
            $user->profile()->create([
                'id_card_number' => $idCard ?: $faker->numerify('1####000#####'),
                'id_card_number_hash' => hash('sha256', ($idCard ?: $faker->numerify('1####000#####')).config('app.key')),
                'date_of_birth' => $faker->date('Y-m-d', '-20 years'),
                'nationality' => 'ไทย',
                'ethnicity' => 'ไทย',
                'title' => 'นาย',
                'first_name' => $fname,
                'last_name' => $lname,
                'gender' => 'ชาย',
                'religion' => 'พุทธ',
                'house_number' => $faker->buildingNumber,
                'province' => 'กรุงเทพมหานคร',
                'district' => 'พระนคร',
                'sub_district' => 'พระบรมมหาราชวัง',
                'zipcode' => '10200',
                'position' => $position,
                'start_date' => $faker->date('Y-m-d', '-2 years'),
                'status' => 'ปฏิบัติหน้าที่',
            ]);
        }
    }
}
