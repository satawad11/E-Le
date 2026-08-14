<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * สิทธิ์งานสารบรรณ
 *
 * LEGACY-04 (ปิดงาน) · Decision 2 — เดิม seeder ตัวนี้ **สร้าง role ขึ้นมาเอง**
 * ห้าชื่อ (`admin_saraban`, `Saraban Officer`, `เจ้าหน้าที่สารบัญ`, `manager`,
 * `staff`) แล้วแจกสิทธิ์ให้ role เหล่านั้น
 *
 * ปัญหาคือไม่มี role ชื่อเหล่านั้นบนระบบจริงเลยสักตัว — ผู้ใช้จริงถือ
 * `Document Officer` / `Director` / `Deputy Director` / `HR Head` / `Employee`
 * ผลคือโมดูลสารบรรณใช้ได้เฉพาะ Super Admin มาตลอด (LEGACY-04-REPORT ข้อ 2)
 * และการรัน seeder ตัวนี้ก็ยิ่งเพิ่ม role ผีเข้าไปในฐานข้อมูล
 *
 * เจ้าของระบบตัดสินเมื่อ 2026-08-14 ว่า **`Document Officer` คือเจ้าหน้าที่สารบรรณ**
 * และให้เลิกอ้างชื่อ role เก่าทั้งหมด การแจกสิทธิ์จึงย้ายไปอยู่ที่
 * `OperationalPermissionSeeder` ซึ่งเป็นแหล่งความจริงเดียวและแจกตามหน้าที่จริง
 *
 * คงคลาสนี้ไว้เพราะมีโค้ดและชุดทดสอบอ้างถึงอยู่ และเพื่อให้ `db:seed` เดิมยังทำงานได้
 */
class SarabanPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(OperationalPermissionSeeder::class);
    }
}
