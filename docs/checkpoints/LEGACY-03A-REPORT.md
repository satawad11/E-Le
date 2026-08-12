# LEGACY-03A — Organization, Personnel & Public UUID

วันที่: 2026-08-13
สถานะ: **PASS**

## ผลลัพธ์

- เพิ่ม `org_units` แบบ hierarchy/effective-dated และ root organization ที่ไม่ hard-code ชื่อหน่วยงาน
- เพิ่ม `department_org_unit_map` และ backfill ทุก department เป็นหน่วยระดับแรกใต้ root
- เพิ่ม `users.org_unit_id` แบบ nullable และ backfill จาก `department_id` โดยคง `department_id` ไว้
- เพิ่ม `personnel_assignments` พร้อมประวัติสังกัด/ประเภทบุคลากร/ตำแหน่งแบบ effective-dated
- เพิ่ม UUID แบบ additive + unique index ให้ `users`, `leaves`, `attendances`, `saraban_documents`
- dual-write เมื่อสร้าง/แก้ department และเมื่อสร้าง/ย้ายสังกัดหรือเปลี่ยนประเภทบุคลากร

## Test-first / Verification

- ก่อนแก้: schema/UUID/dual-write tests ล้มเหลวตามช่องว่าง
- หลังแก้: `CoreDataFoundationTest` ผ่าน 3 tests / 10 assertions
- ไม่ drop/rename schema และไม่สลับ route binding ใน phase นี้

## Migration / Rollback

- Migration: `2026_08_13_100000_create_core_data_foundation.php`
- rollback จะลบเฉพาะ schema ใหม่; integer IDs และคอลัมน์เดิมไม่เปลี่ยน
