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

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SarabanPermissionSeeder::class,
            SarabanDocumentTypeSeeder::class,
            SarabanSettingSeeder::class,
            FiscalYearSeeder::class,
            ThaiGovernmentHoliday2569Seeder::class,
            DocumentTemplateSeeder::class,
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

        // Seed leave balances for current year
        $currentYear = (int) date('Y');
        $quotas = [
            'ลาป่วย' => 60,
            'ลากิจส่วนตัว' => 45,
            'ลาพักผ่อน' => 10,
            'ลาคลอดบุตร' => 90,
            'ลาไปช่วยเหลือภริยาที่คลอดบุตร' => 15,
        ];

        $userIds = [$superAdmin->id, $director->id, $deputy->id, $hrHead->id, $employee->id, $docOfficer->id];

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
