<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // Docker supplies development database variables to every process.
        // Override them before Laravel boots so tests never touch development data.
        putenv('APP_ENV=testing');
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');

        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
        $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = ':memory:';

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * R-05 (EnsureMfaSatisfied เป็น fail-closed): เทสต์สิทธิ์ส่วนใหญ่ใช้ Sanctum::actingAs ซึ่งสร้าง token จำลองที่ไม่มีแถว
         * จึงเปิดข้อยกเว้นไว้ที่นี่โดยชัดแจ้ง — มีผลเฉพาะตอนรันเทสต์ · เทสต์ที่ตรวจการบังคับ MFA ปิดค่านี้เอง (Access/MfaTest)
         */
        config(['auth.mfa.allow_transient_in_tests' => true]);
    }
}
