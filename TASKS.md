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
- [x] **ยืนยัน schema production จริง** — ทำใน LEGACY-00: drift = 0
- [x] **รัน `php artisan test` จริง** — ทำใน LEGACY-00: 86/86 ผ่าน (หักล้างข้อสรุปเดิม)
- [x] ตอบ Open Questions — ตอบได้แล้ว 3 ข้อ (#3, #4, #5) เหลือ 4 ข้อเดิม + 3 ข้อใหม่
- [x] หยุดและรออนุมัติก่อนแก้ Source Code — **ผู้ใช้อนุมัติในหลักการแล้ว 2026-08-12**

## LEGACY-00 — FREEZE & BASELINE ✅ เสร็จ 2026-08-12 (ไม่มี FAIL)

รายงาน: `docs/checkpoints/LEGACY-00-REPORT.md`

- [x] สร้าง baseline commit `6139212` + tag `baseline/legacy-2026-08-12` (258 ไฟล์)
- [x] ตรวจ working tree — clean หลัง commit, ไม่มี secret/vendor/ไฟล์ผู้ใช้หลุดเข้า repo
- [x] ตรวจ migration status — 91/91 `Ran`, 0 `Pending`
- [x] ตรวจ schema จริงจาก MySQL 8.0.46 — 55 ตาราง
- [x] เปรียบเทียบ schema จริงกับ migration — **drift = 0** (ยืนยัน 3 วิธีอิสระ)
- [x] ตรวจ `leaves.status` / `leave_approvals.status` — **พบ K-01: enum ไม่มีค่า `Voided` → ฟีเจอร์ void พัง** (บันทึกแล้ว ไม่แก้)
- [x] Row count baseline — 298 แถว / 55 ตาราง
- [x] Database backup + ตรวจ BOM — `eleave-full-2026-08-12.sql` (143 kB)
- [x] Backup `storage/app` — `storage-app-2026-08-12.tar.gz` (39 kB, 12 ไฟล์)
- [x] Checksum SHA-256 ทั้ง archive และรายไฟล์
- [x] ทดสอบ restore บน instance แยก — **298/298 แถว, checksum ตรงทุกฟิลด์รวมข้อมูลเข้ารหัส**
- [x] รัน `php artisan test` — 86/86 ผ่าน
- [x] รัน `npm run build` — exit 0
- [x] บันทึก test baseline / schema baseline / rowcount baseline
- [x] บันทึก known issues (K-01 ถึง K-09 ใน `docs/KNOWN-LIMITATIONS.md`)
- [x] สร้าง `docs/checkpoints/LEGACY-00-REPORT.md`
- [x] **ได้รับการตัดสินใจ D-1 ถึง D-4 และอนุมัติให้เริ่ม LEGACY-01**

## LEGACY-01 — STABILIZATION ✅ เสร็จสมบูรณ์ 2026-08-13

รายงาน: `docs/checkpoints/LEGACY-01-REPORT.md`

- [x] ลบ public/debug/test artifacts และยืนยันว่า `/fix-leaves` ไม่มี implementation
- [x] Automated backup + restore drill + worker/scheduler
- [x] CI: Pint, backend tests, fresh MySQL 8 migration, frontend build
- [x] Production debug guard, structured JSON log และ `X-Request-ID`
- [x] แก้ LeaveController null role และเปลี่ยนเป็น permission-based authorization
- [x] เปลี่ยน `leaves.status` เป็น checked varchar รองรับ `Voided`
- [x] เติมเมนูที่หายด้วย idempotent seeder
- [x] หมุน MySQL credential จริงและย้าย application ออกจาก root — ยืนยัน `eleave_app@%`, root เฉพาะ localhost, backup ผ่าน
- [x] ปีงบประมาณ 2567–2571 — seed แบบ idempotent; ปีปัจจุบัน 2569
- [x] วันหยุดราชการทั่วประเทศปี 2569 จำนวน 20 วัน และแม่แบบใบลาใช้งานจริง 5 แบบ — seed แบบ idempotent

## AFTER LEGACY ASSESSMENT APPROVAL

- [x] LEGACY-00 Freeze & Baseline
- [x] LEGACY-01 Stabilization — ผ่าน implementation, credential, ปีงบประมาณ, วันหยุด และแม่แบบเอกสาร
- [x] LEGACY-02 Security Hardening — full regression 195 tests / 485 assertions ผ่าน
  - [x] LEGACY-02A File Security — private storage, MIME/size validation, UUID filenames, resource authorization, nginx deny
  - [x] LEGACY-02B Authorization & IDOR — SEC-003, SEC-004, SEC-009 ผ่านจาก commit ก่อนหน้า
  - [x] LEGACY-02C Authentication & Session — lockout, token expiry/rotation, revoke, password reset, secure production session defaults
  - [x] LEGACY-02D E-Signature Integrity — password ทุก mode, assigned signer, sealed content hash, public throttle
  - [x] LEGACY-02E General Hardening — security headers, safe HTML rendering, generic errors, export/download throttles, route audit
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
