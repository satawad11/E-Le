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
- [x] LEGACY-03 Core Data Foundation — full regression 204 tests / 506 assertions ผ่าน
  - [x] LEGACY-03A Organization, Personnel & UUID foundation (03-1 ถึง 03-5)
  - [x] LEGACY-03B Central audit dual-write (03-6 ถึง 03-7)
  - [x] LEGACY-03C Document template version foundation (03-8)
  - [x] LEGACY-03D Leave status checked varchar (03-9) — ทำแล้วใน LEGACY-01
- [x] LEGACY-04 RBAC + ABAC — ปิดงานเมื่อ 2026-08-14 หลังได้รับการตัดสินใจ 4 ข้อ · `docs/checkpoints/LEGACY-04-FINAL-REPORT.md`
  - [x] LEGACY-04A รากฐาน RBAC + ABAC (04-1 ถึง 04-3, 04-5) — alias, explicit deny, org scope
  - [x] LEGACY-04B ถอด `hasRole` (04-4, 04-8) — เหลือ 3 จุด Super Admin bootstrap · หน้าเว็บเหลือ 1 จุด
  - [x] LEGACY-04C สิทธิ์รายบุคคลแหล่งเดียว (04-6, 04-7)
  - [x] LEGACY-04D authorization test matrix (04-9) — `KNOWN_MISSING_PENDING_DECISION` ว่างแล้ว
  - [x] LEGACY-04E เติม permission 7 ตัวตาม Decision 1 — บุคลากรทั่วไปลงเวลาได้แล้ว
  - [x] LEGACY-04F `Document Officer` = เจ้าหน้าที่สารบรรณตาม Decision 2 (permission ไม่ใช่ชื่อ role)
  - [x] LEGACY-04G `org_unit_managers` รองรับหัวหน้าหน่วยงาน/ผู้รักษาการตาม Decision 3 + 4
  - [x] LEGACY-04H ปิดช่องยกระดับสิทธิ์ 3 จุดที่พบระหว่างทาง
- [x] LEGACY-05 Workflow Engine — 320 tests / 832 assertions ผ่าน · `docs/checkpoints/LEGACY-05-REPORT.md`
  - [x] LEGACY-05A schema 8 ตาราง + shadow comparison log (05-1)
  - [x] LEGACY-05B Leave Workflow v1 จาก `approval_steps` พร้อม version pinning (05-2)
  - [x] LEGACY-05C Shadow mode บน endpoint อนุมัติจริง — ตรงกัน 100% (05-3)
  - [ ] LEGACY-05D Cutover (05-4) — **ยังไม่ทำโดยเจตนา** ต้องมี shadow จากการอนุมัติจริงและคำสั่งอนุมัติ
  - [x] LEGACY-05E เตรียม Saraban เข้า engine เดียวกัน (draft · ไม่แตะสิทธิ์) (05-5)
  - [x] LEGACY-05F delegation / acting approver + audit ครบ (05-6 บางส่วน)
  - [x] LEGACY-05G `approval_steps` / `leave_approvals` คงไว้เป็น read-only (05-7)
- [x] CORE CHECKPOINT — ✅ **PASS · อนุมัติแล้ว 2026-08-14** · `CORE_CHECKPOINT_REPORT.md`
  - [x] P0 gaps = 0 (16/16)
  - [x] P1 gaps = 0 (18/18) — รอบแรกเหลือ 3 · แก้ครบแล้ว
    - [x] `DEPLOY-001` — nginx + php-fpm เป็นทางเข้าเดียว เลิกใช้ `artisan serve`
    - [x] `DEPLOY-004` — เหลือ Dockerfile ชุดเดียว · `docker/nginx/default.conf` ถูกใช้งานจริง
    - [x] `CORE-WF-002` — Saraban pin `workflow_version_id` + ผู้บริหารได้สิทธิ์อนุมัติ/ลงนาม
  - [x] Authorization matrix + IDOR ผ่าน
  - [x] Backup + restore drill สำเร็จ (73 ตาราง · mismatch 0)
  - [x] Data reconciliation ผ่าน (55 → 73 ตาราง อธิบายได้ครบ)
  - [x] migrate จากฐานว่างบน MySQL 8 ครบ 103 migration
  - [x] TRACEABILITY-MATRIX อัปเดตครบ
  - [x] ได้รับอนุมัติรายงานจากเจ้าของระบบ
- [x] LEGACY-06 TIME Foundation — **อนุมัติแล้ว 2026-08-15** · 372 tests / 952 assertions · `docs/checkpoints/LEGACY-06-REPORT.md`
  - [x] 06-1 `work_schedules` / `work_schedule_days` / `work_schedule_assignments` แบบ effective-dated
  - [x] 06-2 backfill จาก setting เดิม โดยไม่แตะ `users.work_start_time`
  - [x] 06-3 grace period (ค่าเริ่มต้น 0 เพื่อรักษาพฤติกรรมเดิม)
  - [x] 06-4 เชื่อม `holidays` + `work_calendar_exceptions` เข้ากับ engine
  - [x] `TimeService` เป็นบริการเวลากลาง — โมดูลอื่นเลิกคำนวณวันและเวลาเอง
  - [ ] **รอตัดสินใจ:** กะข้ามวัน — การลงเวลาหลังเที่ยงคืนนับเป็นของวันไหน (เงื่อนไขก่อนเปิดใช้กะดึกจริง)
- [ ] Leave Modernization
- [ ] Attendance Integration
- [ ] OT
- [ ] Notification Center
- [ ] Evaluation & Reporting
