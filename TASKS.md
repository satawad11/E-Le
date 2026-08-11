# TASKS

## LEGACY-ASSESSMENT — CURRENT (รอบที่ 1 เสร็จ 2026-08-12 · รออนุมัติ)

- [x] อ่าน START-HERE / AGENTS / CLAUDE / PROJECT_RULES
- [x] Inventory repository และ technology stack
- [x] ตรวจ Database schema/migrations — **จาก migration files เท่านั้น ยังไม่ได้ตรวจ DB จริง**
- [x] ตรวจ Authentication
- [x] ตรวจ RBAC/ABAC/authorization/IDOR
- [x] ตรวจ Organization + Personnel
- [x] ตรวจ Workflow/Approval
- [x] ตรวจ Leave
- [x] ตรวจ Attendance
- [x] ตรวจ Vendor face/fingerprint integration — **ผล: ไม่มี integration ใด ๆ ในระบบเดิม**
- [x] ตรวจ Overtime
- [x] ตรวจ Notification
- [x] ตรวจ Reports
- [x] ตรวจ File storage
- [x] ตรวจ Audit
- [x] ตรวจ Testing — **ไม่ได้รันจริง (ไม่มี php/node ในสภาพแวดล้อมที่ตรวจ)**
- [x] ตรวจ Deployment/Backup
- [x] กรอก docs/LEGACY-SYSTEM-ASSESSMENT.md
- [x] กรอก docs/LEGACY-GAP-ANALYSIS.md
- [x] กรอก docs/LEGACY-MIGRATION-PLAN.md
- [x] สรุป Top 10 gaps และ P0/P1 — P0 = 14, P1 = 16
- [ ] **ยืนยัน schema production จริง** (`migrate:status` + `SHOW CREATE TABLE`) — ค้าง ต้องมี php
- [ ] **รัน `php artisan test` จริง** เพื่อยืนยัน/หักล้าง 42 defect ใน `.phpunit.result.cache` — ค้าง
- [ ] ตอบ Open Questions 7 ข้อ (ดู `docs/LEGACY-SYSTEM-ASSESSMENT.md` §18)
- [ ] หยุดและรออนุมัติก่อนแก้ Source Code ← **สถานะปัจจุบัน: หยุดแล้ว**

## AFTER LEGACY ASSESSMENT APPROVAL

- [ ] LEGACY-00 Freeze & Baseline
- [ ] LEGACY-01 Stabilization
- [ ] LEGACY-02 Security Hardening
- [ ] LEGACY-03 Core Data Foundation
- [ ] LEGACY-04 RBAC + ABAC
- [ ] LEGACY-05 Workflow Engine
- [ ] CORE CHECKPOINT
- [ ] TIME Foundation
- [ ] Leave Modernization
- [ ] Attendance Integration
- [ ] OT
- [ ] Notification Center
- [ ] Evaluation & Reporting
