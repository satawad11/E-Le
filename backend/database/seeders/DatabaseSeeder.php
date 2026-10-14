<?php

namespace Database\Seeders;

use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Services\LeavePolicyService;
use Faker\Factory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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
         *   StandardRolesAndPermissionsSeeder      ทับซ้อนกับ RolesAndPermissionsSeeder
         *   UpdateUserNamesSeeder                  แก้ข้อมูลย้อนหลังครั้งเดียว
         *   MockDataSeeder / MockLeaveSeeder       ข้อมูลจำลองสำหรับ dev เท่านั้น
         * ทั้งหมดต้องเรียกด้วยมือเมื่อจำเป็น
         */
        $this->call([
            RolesAndPermissionsSeeder::class,
            SarabanPermissionSeeder::class,
            SarabanDocumentTypeSeeder::class,
            SarabanSettingSeeder::class,
            FiscalYearSeeder::class,
            ThaiGovernmentHoliday2569Seeder::class,
            ThaiGovernmentHoliday2570Seeder::class,
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
        $this->call([NewLeaveTypesSeeder::class]);

        // Create Users
        $faker = Factory::create('th_TH');

        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@example.com'],
            ['name' => 'ผู้ดูแลระบบ สูงสุด', 'password' => bcrypt('1111111111111'), 'department_id' => $itDept->id]
        );
        $superAdmin->assignRole($superAdminRole);
        $this->seedProfile($superAdmin, $faker, 'ทดสอบ', 'ระบบ', 'ผู้ดูแลระบบ', '1111111111111');

        $director = User::firstOrCreate(
            ['email' => 'director@example.com'],
            ['name' => 'สมศักดิ์ ผู้อำนวยการ', 'password' => bcrypt('2222222222222'), 'department_id' => $hrDept->id]
        );
        $director->assignRole($directorRole);
        $this->seedProfile($director, $faker, 'สมศักดิ์', 'ผู้อำนวยการ', 'ผู้อำนวยการ', '2222222222222');

        $deputy = User::firstOrCreate(
            ['email' => 'deputy@example.com'],
            ['name' => 'สมชาย รองผู้อำนวยการ', 'password' => bcrypt('3333333333333'), 'department_id' => $hrDept->id]
        );
        $deputy->assignRole($deputyRole);
        $this->seedProfile($deputy, $faker, 'สมชาย', 'รองผู้อำนวยการ', 'รองผู้อำนวยการ', '3333333333333');

        $hrHead = User::firstOrCreate(
            ['email' => 'hrhead@example.com'],
            ['name' => 'สมปอง หัวหน้าบุคลากร', 'password' => bcrypt('4444444444444'), 'department_id' => $hrDept->id]
        );
        $hrHead->assignRole($hrHeadRole);
        $this->seedProfile($hrHead, $faker, 'สมปอง', 'หัวหน้าบุคลากร', 'หัวหน้าบุคลากร', '4444444444444');

        $employee = User::firstOrCreate(
            ['email' => 'employee@example.com'],
            ['name' => 'สมหญิง พนักงานทั่วไป', 'password' => bcrypt('5555555555555'), 'department_id' => $hrDept->id]
        );
        $employee->assignRole($employeeRole);
        $this->seedProfile($employee, $faker, 'สมหญิง', 'พนักงานทั่วไป', 'พนักงานทั่วไป', '5555555555555');

        $docOfficer = User::firstOrCreate(
            ['email' => 'doc@example.com'],
            ['name' => 'สมเกียรติ เจ้าหน้าที่สารบัญ', 'password' => bcrypt('6666666666666'), 'department_id' => $itDept->id]
        );
        $docOfficer->assignRole($docOfficerRole);
        $this->seedProfile($docOfficer, $faker, 'สมเกียรติ', 'เจ้าหน้าที่สารบัญ', 'เจ้าหน้าที่สารบัญ', '6666666666666');

        // ยอดวันลาตั้งต้นของปีงบประมาณปัจจุบัน — สร้างตามนโยบายสิทธิการลา (leave_policies)
        // เดิม hard-code โควตาไว้ที่นี่ (ลาป่วย 60, ลาคลอด 90) ซึ่งไม่ตรงกับนโยบาย (120, 98)
        // และใช้ date('Y') เป็นปี ทำให้ตั้งแต่ 1 ต.ค. ยอดที่ seed ไว้เป็นของปีงบที่ปิดไปแล้ว
        $policies = app(LeavePolicyService::class);
        foreach ([$superAdmin, $director, $deputy, $hrHead, $employee, $docOfficer] as $seededUser) {
            $policies->ensureBalances($seededUser->fresh(['personnel', 'currentAssignment', 'profile']), today());
        }

        // Seed dynamic approval steps matching the default workflow
        // ทุกขั้นอนุมัติใบลาของตนเองได้ ตามมติเจ้าของระบบ 2026-10-14 (LEAVE-APPROVAL-02)
        ApprovalStep::firstOrCreate(
            ['step_order' => 1],
            ['role_id' => $hrHeadRole->id, 'name' => 'ตรวจสอบโดยหัวหน้าบุคลากร', 'allow_self_approval' => true]
        );
        ApprovalStep::firstOrCreate(
            ['step_order' => 2],
            ['role_id' => $deputyRole->id, 'name' => 'พิจารณาโดยรองผู้อำนวยการ', 'allow_self_approval' => true]
        );
        ApprovalStep::firstOrCreate(
            ['step_order' => 3],
            ['role_id' => $directorRole->id, 'name' => 'อนุมัติรอบสุดท้ายโดยผู้อำนวยการ', 'allow_self_approval' => true]
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
