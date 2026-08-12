# CHANGELOG

## Unreleased
- Initial documentation pack created.

### LEGACY-01 Stabilization

- เพิ่ม automated backup พร้อม restore drill, queue worker และ scheduler
- เพิ่ม CI สำหรับ Pint, backend tests, fresh migration บน MySQL 8 และ frontend build
- เพิ่ม production debug guard, structured JSON logging และ request correlation ID
- แก้ LeaveController เมื่อผู้ใช้ไม่มี role และใช้ permission แทนชื่อ role
- เปลี่ยน `leaves.status` จาก ENUM เป็น `VARCHAR(20)` + CHECK เพื่อรองรับ `Voided`
- ลบ debug/test artifacts และอัปเดต Dompdf เป็น 3.1.6 เพื่อปิด security advisories
- เติมเมนู core 15 รายการด้วย idempotent seeder

## 2026-08-12 — Legacy Modernization Pack

- Added Legacy System Assessment
- Added Legacy Gap Analysis
- Added Legacy Migration Plan
- Added Legacy Upgrade Prompt
- Changed current phase to LEGACY-ASSESSMENT for existing-system upgrades
