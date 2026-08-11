<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\UserProfile;
use App\Models\Department;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

// 1. Ensure Standard Roles & Permissions Seeder is run
$seeder = new \Database\Seeders\StandardRolesAndPermissionsSeeder();
$seeder->run();

// Departments
$itDept = Department::firstOrCreate(['name' => 'ฝ่ายสารสนเทศ'], ['description' => 'ฝ่ายเทคโนโลยีสารสนเทศ']);
$hrDept = Department::firstOrCreate(['name' => 'ฝ่ายทรัพยากรบุคคล'], ['description' => 'ฝ่ายบริหารทรัพยากรบุคคล']);

$accounts = [
    [
        'email' => 'superadmin@example.com',
        'name' => 'ผู้ดูแลระบบ สูงสุด',
        'password' => '1111111111111',
        'id_card' => '1111111111111',
        'role' => 'ผู้ดูแลระบบสูงสุด',
        'dept' => $itDept->id,
        'title' => 'นาย',
        'fname' => 'ผู้ดูแลระบบ',
        'lname' => 'สูงสุด',
        'position' => 'ผู้ดูแลระบบสูงสุด',
    ],
    [
        'email' => 'director@example.com',
        'name' => 'สมศักดิ์ ผู้อำนวยการ',
        'password' => '2222222222222',
        'id_card' => '2222222222222',
        'role' => 'ผู้อำนวยการ',
        'dept' => $hrDept->id,
        'title' => 'นาย',
        'fname' => 'สมศักดิ์',
        'lname' => 'ผู้อำนวยการ',
        'position' => 'ผู้อำนวยการ',
    ],
    [
        'email' => 'deputy@example.com',
        'name' => 'สมชาย รองผู้อำนวยการ',
        'password' => '3333333333333',
        'id_card' => '3333333333333',
        'role' => 'รองผู้อำนวยการ',
        'dept' => $hrDept->id,
        'title' => 'นาย',
        'fname' => 'สมชาย',
        'lname' => 'รองผู้อำนวยการ',
        'position' => 'รองผู้อำนวยการ',
    ],
    [
        'email' => 'hrhead@example.com',
        'name' => 'สมปอง หัวหน้าบุคลากร',
        'password' => '4444444444444',
        'id_card' => '4444444444444',
        'role' => 'เจ้าหน้าที่บุคลากร (HR)',
        'dept' => $hrDept->id,
        'title' => 'นาย',
        'fname' => 'สมปอง',
        'lname' => 'หัวหน้าบุคลากร',
        'position' => 'หัวหน้างานบุคลากร',
    ],
    [
        'email' => 'employee@example.com',
        'name' => 'สมหญิง พนักงานทั่วไป',
        'password' => '5555555555555',
        'id_card' => '5555555555555',
        'role' => 'บุคลากร / ครู / เจ้าหน้าที่',
        'dept' => $hrDept->id,
        'title' => 'นางสาว',
        'fname' => 'สมหญิง',
        'lname' => 'พนักงานทั่วไป',
        'position' => 'ครูผู้สอน',
    ],
    [
        'email' => 'doc@example.com',
        'name' => 'สมเกียรติ เจ้าหน้าที่สารบัญ',
        'password' => '6666666666666',
        'id_card' => '6666666666666',
        'role' => 'เจ้าหน้าที่สารบรรณกลาง',
        'dept' => $itDept->id,
        'title' => 'นาย',
        'fname' => 'สมเกียรติ',
        'lname' => 'เจ้าหน้าที่สารบัญ',
        'position' => 'เจ้าหน้าที่งานสารบรรณ',
    ],
];

foreach ($accounts as $acc) {
    $user = User::where('email', $acc['email'])->first();
    if (!$user) {
        $user = new User();
        $user->email = $acc['email'];
    }
    $user->name = $acc['name'];
    $user->password = bcrypt($acc['password']);
    $user->department_id = $acc['dept'];
    $user->is_active = true;
    $user->save();

    $user->syncRoles([$acc['role']]);

    $profile = $user->profile;
    if (!$profile) {
        $profile = new UserProfile(['user_id' => $user->id]);
    }
    $profile->id_card_number = $acc['id_card'];
    $profile->id_card_number_hash = hash('sha256', $acc['id_card'] . config('app.key'));
    $profile->title = $acc['title'];
    $profile->first_name = $acc['fname'];
    $profile->last_name = $acc['lname'];
    $profile->position = $acc['position'];
    $profile->save();

    echo "Synced account: {$user->email} with role: {$acc['role']}\n";
}

// Purge obsolete roles
$purgeSeeder = new \Database\Seeders\PurgeAndMigrateTo7StandardRolesSeeder();
$purgeSeeder->run();

echo "All accounts synced successfully!\n";
