<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:run')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('leave:governance')->weekdays()->hourly()->between('08:00', '17:00')->withoutOverlapping();
Schedule::command('leave:governance --purge')->monthlyOn(1, '03:00')->withoutOverlapping();
// R-05 (CORE-03): JML รายวัน + ปลดล็อก + ระงับบัญชีไม่เข้าใช้ + ปิด grant หมดอายุ + ปิดรอบทบทวนสิทธิ์ที่เลยกำหนด
// แทน access:expire-role-grants ของระบบเดิม (ตาราง user_role_grants ถูกตัดใน R-02)
Schedule::command('accounts:reconcile')->dailyAt('00:30')->withoutOverlapping();
Schedule::command('app:carry-forward-leaves')->yearlyOn(10, 1, '00:10')->withoutOverlapping();
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
