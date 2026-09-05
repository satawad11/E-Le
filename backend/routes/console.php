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

/*
 * INT-14 — กวาด outbox ของศูนย์แจ้งเตือน (SoT §16)
 *
 * ตารางเดลิเวอรีเป็น outbox ที่แท้จริงได้ต่อเมื่อมีตัวกวาดคอยหาแถวที่ควรถูกส่ง
 * ไปแล้วแต่ยังค้าง (worker ถูกฆ่ากลางทาง คิวถูกล้าง หรือ deploy ตัดงานทิ้ง)
 * ทุก 5 นาทีเพราะการแจ้งเตือนราชการช้าได้ระดับนาที แต่หายไม่ได้
 */
Schedule::command('notifications:dispatch-outbox')->everyFiveMinutes()->withoutOverlapping();

/*
 * INT-15 — outbox ของ webhook และการล้างกุญแจกันทำซ้ำ (SoT §17)
 *
 * ใช้กติกาเดียวกับ outbox ของศูนย์แจ้งเตือน · ส่วนกุญแจกันทำซ้ำต้องถูกล้าง
 * ไม่งั้นตารางนี้จะกลายเป็นตารางที่ใหญ่ที่สุดในระบบภายในไม่กี่เดือน
 */
Schedule::command('webhooks:dispatch-outbox')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('idempotency:prune')->hourly()->withoutOverlapping();

/*
 * INT-17 — ยก dead-letter ที่ค้างเข้าคิวข้อผิดพลาด (SoT §19)
 *
 * ทุก 15 นาทีพอ เพราะ dead-letter เกิดหลังจากลองใหม่ครบแล้วซึ่งกินเวลาหลายนาที
 * อยู่แล้ว · คำสั่งนี้ประทับชีพจรของตัวเองด้วย จึงเฝ้าระวังตัวมันเองได้
 */
Schedule::command('operations:sweep-exceptions')->everyFifteenMinutes()->withoutOverlapping();

/*
 * INT-20 — ยกงานตามเวลาที่หยุดทำงานเข้าคิวข้อผิดพลาด (ปิด RISK-46 · SoT §20)
 *
 * ทุก 15 นาที · **ตัวมันเองก็เป็นงานตามเวลา** ถ้า schedule:run ตายทั้งหมด
 * คำสั่งนี้ก็ไม่ทำงาน — การเฝ้าระวังชั้นสุดท้ายต้องมาจากภายนอกระบบ
 * (uptime monitor ที่เรียก /api/ping) ซึ่งอยู่นอกขอบเขตของโค้ดนี้
 */
Schedule::command('operations:check-heartbeats')->everyFifteenMinutes()->withoutOverlapping();
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
