# LEGACY-03C — Document Template Versions

วันที่: 2026-08-13
สถานะ: **PASS**

## ผลลัพธ์

- เพิ่ม `document_template_versions` พร้อม version, layout/content snapshot และ effective dates
- backfill template ปัจจุบันเป็น v1
- เมื่อแก้ layout/content จะปิดช่วง version เดิมและสร้าง version ใหม่
- เพิ่ม `leaves.document_template_version_id` แบบ nullable และ pin active leave template version
- คง `document_templates` และ export path เดิมไว้; phase นี้สร้าง foundation ก่อน switch reads ใน LEGACY-07

## Verification

- test-first: ก่อนแก้ไม่พบ schema/model/version pin
- targeted suite ผ่าน 8 tests / 22 assertions
- reference-data seeder และ Leave PDF/Word export regression ผ่าน

## Migration / Rollback

- Migration: `2026_08_13_120000_create_document_template_versions.php`
- rollback ลบเฉพาะ FK/version table; template และใบลาเดิมยังคงอยู่
