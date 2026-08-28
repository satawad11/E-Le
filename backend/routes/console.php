<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:run')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('leave:governance')->weekdays()->hourly()->between('08:00', '17:00')->withoutOverlapping();
Schedule::command('leave:governance --purge')->monthlyOn(1, '03:00')->withoutOverlapping();
/*
 * เปิดรอบปีงบประมาณ — ต้องรัน **ก่อน** การทบวันลาเสมอ
 *
 * คำสั่งเปิดรอบสร้างแถวโควตาของปีใหม่ ส่วนคำสั่งทบวันลาเขียนทับ `carried_forward`
 * ของแถวเหล่านั้น สลับลำดับแล้ววันที่ทบไว้จะถูกแถวที่สร้างทีหลังทับหาย
 */
Schedule::command('leave:open-fiscal-year')->yearlyOn(10, 1, '00:05')->withoutOverlapping();
Schedule::command('app:carry-forward-leaves')->yearlyOn(10, 1, '00:10')->withoutOverlapping();

/*
 * ซ่อมยอดวันลาที่เพี้ยนจากเหตุที่ระบบจับไม่ได้เอง เช่น การแก้ข้อมูลตรงฐาน
 * หรือคำขอยกเลิกบางช่วงที่ซ้อนกัน — คำนวณ used/reserved ใหม่จากใบลาจริง
 */
Schedule::command('leave:reconcile-balances')->weeklyOn(0, '03:30')->withoutOverlapping();
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
