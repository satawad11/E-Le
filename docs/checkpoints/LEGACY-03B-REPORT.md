# LEGACY-03B — Central Audit Dual-write

วันที่: 2026-08-13
สถานะ: **PASS**

## ผลลัพธ์

- เพิ่ม `audit_logs` กลาง: actor/effective actor, action, resource, before/after, reason, IP, user agent, correlation ID และ timestamp
- dual-write จาก `leave_audit_logs`, `leave_action_logs`, `saraban_audit_logs`, `personal_data_access_logs` และ `setting_audit_logs`
- เพิ่ม audit สำหรับ user, attendance, role และ direct permission mutations
- ป้องกัน update/delete ผ่าน central AuditLog model เพื่อรักษา append-oriented semantics

## Verification

- test-first: ก่อนแก้ไม่พบ schema และ central rows
- targeted regression ผ่าน 27 tests / 88 assertions
- existing Saraban, system settings และ PDPA audit tests ยังผ่าน

## Migration / Rollback

- Migration: `2026_08_13_110000_create_central_audit_logs_table.php`
- ตาราง audit เดิมยังคงอยู่ครบ; rollback ลบเฉพาะ central table/dual-write code
