# LEGACY-03 — Core Data Foundation

วันที่: 2026-08-13
สถานะ: **PASS / COMPLETE**

## ขอบเขตที่เสร็จ

- 03-1/03-2: organization hierarchy + department mapping/backfill
- 03-3: `users.org_unit_id` + backfill/dual-write โดยคง `department_id`
- 03-4: effective-dated `personnel_assignments` + backfill
- 03-5: additive UUID สำหรับ users, leaves, attendances และ saraban_documents
- 03-6/03-7: central append-oriented audit + legacy/new mutation dual-write
- 03-8: document template versions + leaves version pin
- 03-9: checked varchar สำหรับ `leaves.status` ทำเสร็จแล้วใน LEGACY-01 จึงไม่ทำ DDL ซ้ำ

## Test-first และ Final Verification

- Foundation: 3 tests / 10 assertions
- Central audit targeted regression: 27 tests / 88 assertions
- Template targeted regression: 8 tests / 22 assertions
- Backend full suite: **204 tests / 506 assertions — PASS**
- Laravel Pint: **316 files — PASS**
- Composer audit: **0 advisories**
- Frontend build: **879 modules — PASS**
- Production database migrations 3 ชุดใหม่: **Ran**

## Migrations

- `2026_08_13_100000_create_core_data_foundation.php`
- `2026_08_13_110000_create_central_audit_logs_table.php`
- `2026_08_13_120000_create_document_template_versions.php`

## ข้อจำกัด / ขั้นถัดไป

- UUID ถูกสร้างและ backfill แล้ว แต่ route binding ยังใช้ integer ID เพื่อไม่เปลี่ยน public API ใน phase foundation; การ switch URL ต้องทำเป็น compatibility rollout แยก
- `document_template_version_id` pin แล้ว แต่ export ยังอ่าน template path เดิมจนถึง LEGACY-07 ตาม migration plan
- central audit ทำ dual-write; ตาราง audit เดิมยังคงเป็น source สำหรับหน้าจอเดิม
- ยังไม่เริ่ม LEGACY-04
