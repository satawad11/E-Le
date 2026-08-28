<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * SEC-028 — การติดตั้งจริงต้องไม่ได้บัญชีที่ใครก็เดารหัสผ่านได้
 *
 * `DatabaseSeeder` เคยสร้างผู้ใช้หกคนรวมถึง Super Admin ด้วยรหัสผ่านตายตัว
 * (`1111111111111` ถึง `6666666666666`) ระบบที่เพิ่ง `db:seed --force` ขึ้น production
 * จึงเปิดให้เข้าเป็นผู้ดูแลระบบสูงสุดได้ทันทีตั้งแต่วินาทีแรก
 *
 * ทุกเทสต์ที่นี่รันผ่าน `db:seed --force` เหมือนขั้นตอนติดตั้งจริง เพราะเส้นทางนั้น
 * คือเส้นทางเดียวที่ผู้ดูแลระบบใช้จริงบน production
 */
class ProductionSeedSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('ADMIN_PASSWORD');

        parent::tearDown();
    }

    public function test_a_production_install_creates_no_demo_accounts(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        putenv('ADMIN_PASSWORD=cH4ng3-me-on-first-login');

        Artisan::call('db:seed', ['--force' => true]);

        foreach (['director@example.com', 'deputy@example.com', 'hrhead@example.com', 'employee@example.com', 'doc@example.com'] as $email) {
            // บัญชีทดลองทุกตัวต้องไม่ถูกสร้างบน production
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
    }

    public function test_the_administrator_password_comes_from_the_environment_not_the_source(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        putenv('ADMIN_PASSWORD=cH4ng3-me-on-first-login');

        Artisan::call('db:seed', ['--force' => true]);

        $admin = User::where('email', 'superadmin@example.com')->firstOrFail();

        $this->assertFalse(Hash::check('1111111111111', $admin->password), 'รหัสผ่านตายตัวเดิมต้องใช้ไม่ได้อีก');
        $this->assertTrue(Hash::check('cH4ng3-me-on-first-login', $admin->password));
    }

    /** ไม่ตั้ง `ADMIN_PASSWORD` ก็ยังติดตั้งได้ แต่ต้องได้รหัสสุ่ม ไม่ใช่ค่าที่เดาได้ */
    public function test_without_an_environment_password_the_administrator_gets_a_random_one(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        Artisan::call('db:seed', ['--force' => true]);

        $admin = User::where('email', 'superadmin@example.com')->firstOrFail();

        foreach (['1111111111111', 'password', 'superadmin@example.com'] as $guess) {
            $this->assertFalse(Hash::check($guess, $admin->password));
        }
    }

    /** บนเครื่องพัฒนาและในชุดทดสอบ ชุดบัญชีครบทุกบทบาทยังต้องมีเหมือนเดิม */
    public function test_local_installs_still_get_the_full_set_of_demo_accounts(): void
    {
        Artisan::call('db:seed', ['--force' => true]);

        $this->assertDatabaseHas('users', ['email' => 'employee@example.com']);
        $this->assertTrue(Hash::check('1111111111111', User::where('email', 'superadmin@example.com')->firstOrFail()->password));
    }
}
