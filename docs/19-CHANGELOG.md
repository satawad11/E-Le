# CHANGELOG

## Unreleased
- Initial documentation pack created.

### LEGACY-06 Time Foundation

- ปิด LEGACY-06 ครบ 06-1 ถึง 06-4 · **เจ้าของระบบอนุมัติเมื่อ 2026-08-15**; full regression ผ่าน 372 tests / 952 assertions, Pint 373 files และ frontend build
- ค่าที่ยืนยันตอนอนุมัติ: grace period = 0 (รักษาพฤติกรรมเดิม) · เวลาราชการเริ่มต้น 08:30–16:30 จันทร์–ศุกร์
- เพิ่ม `work_schedules` + `work_schedule_days` + `work_schedule_assignments` แบบ effective-dated รองรับกะ กะข้ามวัน และ timezone รายตาราง
- เพิ่มบริการเวลากลาง `TimeService` — โมดูลอื่นเลิกคำนวณวันและเวลาเอง
- รวมตรรกะเวลาที่เคยกระจายสองที่: `LeaveService::calculateTotalDays()` และ `AttendanceController` ทั้งการมาสายและการกลับก่อนเวลา
- แก้ข้อบกพร่องจริง: โมดูลลงเวลาเดิมไม่รู้จักวันหยุดเลย จึงนับ "มาสาย" ได้แม้ในวันหยุดราชการ
- เพิ่ม grace period (`grace_minutes`) — เดิมสายทันทีที่เกินเวลาเริ่มงาน 1 วินาที · ตั้งค่าเริ่มต้น 0 เพื่อรักษาพฤติกรรมเดิม
- นำ `holidays`, `work_calendar_exceptions`, `fiscal_years` ของเดิมมาเชื่อมเข้า engine โดยไม่สร้างซ้ำ
- backfill ตารางเวลาเริ่มต้นจาก setting ที่มีอยู่ โดย **ไม่แตะ** `users.work_start_time` / `work_end_time`

### CORE CHECKPOINT (2026-08-14)

- ตรวจ CORE CHECKPOINT ครบทุกเงื่อนไข — ผล **PASS**: P0 = 0 (16/16) · P1 = 0 (18/18) · **เจ้าของระบบอนุมัติเมื่อ 2026-08-14**
- ปิด `DEPLOY-001` — เพิ่ม service `nginx` เป็นทางเข้าเดียว, backend เปลี่ยนเป็น `php-fpm` และไม่เปิดพอร์ตออกนอก เลิกใช้ `php artisan serve`
- ปิด `DEPLOY-004` — ลบ `/Dockerfile` + `/docker-entrypoint.sh` ที่ซ้ำซ้อน เหลือชุดเดียว และ `docker/nginx/default.conf` ถูกใช้งานจริงแล้ว (กฎ `/storage` → 404 เคยเป็น dead code)
- ปิด `CORE-WF-002` — ให้ ผอ./รอง ผอ. มีสิทธิ์กลั่นกรองและลงนามหนังสือราชการ แล้วเปิด workflow version pinning ให้เอกสารทุกฉบับ pin `workflow_version_id` ของตัวเอง
- ยืนยัน migration จากฐานข้อมูลว่างบน MySQL 8 ครบทั้ง 103 migration (TEST-003)
- full regression 332 tests / 871 assertions · Pint 362 files · frontend build ผ่าน · `composer audit` ไม่พบช่องโหว่
- ซ้อม backup + restore drill ใหม่บน schema ปัจจุบัน — 73 ตาราง ตรงกันทั้งหมด
- data reconciliation เทียบ baseline LEGACY-00 — 55 → 73 ตาราง อธิบายได้ครบ ไม่มีตารางใดมีแถวลดลง
- **ไม่เริ่ม LEGACY-06 และไม่ทำ Workflow Cutover** ตามเงื่อนไขที่ยังไม่ผ่าน

### LEGACY-05 Workflow Engine

- ปิด LEGACY-05 (05-1 ถึง 05-3, 05-5 ถึง 05-7); full regression ผ่าน 320 tests / 832 assertions, Pint 359 files และ frontend build
- เพิ่ม workflow engine กลางแบบ versioned 9 ตาราง (definitions/versions/steps/transitions/instances/tasks/actions/delegations + shadow comparison) แบบ additive ล้วน
- แปลง `approval_steps` ที่ใช้อยู่เป็น Leave Workflow v1 โดย instance ทุกตัว pin `workflow_version_id` — แก้สายอนุมัติแล้วรายการเก่าไม่เปลี่ยนความหมาย
- เปิด shadow mode บน endpoint อนุมัติจริง โดยตรรกะเดิมยังเป็น source of truth ทั้งหมด (`WORKFLOW_SHADOW_MODE`)
- shadow จับ mismatch ได้จริง 1 กรณี: ใบลาที่ยกเลิกก่อนมีใครอนุมัติจะกลับมาโผล่ในคิวรออนุมัติ — แก้แล้วพร้อมเทสต์ล็อก
- เชื่อม `user_delegations` เข้า engine พร้อมแยกผู้กดจริงออกจากผู้ที่ควรเป็นเจ้าของงาน และตรวจ effective date ทุกครั้ง
- บันทึก workflow action แบบ append-only พร้อม actor/effective actor/from-to step/correlation id
- เตรียมสายงานสารบรรณเป็น draft โดยไม่แตะสิทธิ์ที่ปิดไว้ใน LEGACY-04
- **ยังไม่ cutover** — `approval_steps` และ `leave_approvals` ยังเป็น source of truth และคงไว้ทั้งหมด

### LEGACY-04 RBAC + ABAC (ปิดงาน)

- ปิด LEGACY-04 ครบ 04-1 ถึง 04-9 หลังได้รับการตัดสินใจ 4 ข้อจากเจ้าของระบบเมื่อ 2026-08-14; full regression ผ่าน 268 tests / 693 assertions, Pint 334 files และ frontend build
- เพิ่ม permission 7 ตัวที่ route บังคับใช้แต่ไม่เคยถูกสร้าง และแจกตามหน้าที่จริง — แก้ข้อบกพร่องที่ทำให้ **บุคลากรทั่วไปลงเวลาเข้า-ออกไม่ได้เลย**
- กำหนดให้ `Document Officer` เป็นเจ้าหน้าที่สารบรรณตามสิทธิ์ `saraban.document.register` และเลิกอ้างชื่อ role เก่า (`admin_saraban`, `Saraban Officer`, `เจ้าหน้าที่สารบัญ`) ทั้งฝั่ง server และหน้าเว็บ
- แยกสิทธิ์งานสารบรรณ 6 ด้านตามหน้าที่ โดยไม่ให้สิทธิ์ลบเอกสาร เอกสารลับ และตั้งค่าระบบแก่เจ้าหน้าที่สารบรรณโดยอัตโนมัติ
- ปิดช่องยกระดับสิทธิ์ 2 จุดที่มีมาก่อนหน้า: ผู้ถือ `edit_users` เคยมอบ permission ให้ผู้อื่นและสร้าง role ใหม่ได้
- แยก `/api/saraban/settings` ออกจาก `/api/admin/settings` เพื่อไม่ให้สิทธิ์ตั้งค่าสารบรรณเปิดค่า SMTP/Telegram/LINE และค่าลับของทั้งระบบ
- เพิ่ม `org_unit_managers` แบบ effective-dated รองรับหัวหน้าแผนก/หัวหน้างาน/หัวหน้าฝ่าย/ผู้รักษาการ โดยไม่สร้าง role สำหรับตำแหน่งเหล่านี้
- `hasRole` ใน `app/` เหลือ 3 จุดที่เป็น Super Admin bootstrap และมีเทสต์บังคับไม่ให้เพิ่มจุดใหม่

### LEGACY-03 Core Data Foundation

- ปิด LEGACY-03 ครบ 03-1 ถึง 03-9 (03-9 ทำไว้ใน LEGACY-01); full regression ผ่าน 204 tests / 506 assertions, Pint 316 files, Composer audit 0 advisory และ frontend build 879 modules

- เพิ่ม organization hierarchy, department mapping, users.org_unit_id, effective-dated personnel assignments และ UUID สำหรับ public resources แบบ additive พร้อม backfill/dual-write
- เพิ่ม central append-oriented audit log และ dual-write จาก Leave, Saraban, PDPA, settings พร้อม audit mutation ของ user, attendance, role และ permission
- เพิ่ม document template versions แบบ effective-dated และ pin version บนใบลา โดยไม่ overwrite version เดิม

### LEGACY-02 Security Hardening

- ปิด LEGACY-02 ครบ 02-A ถึง 02-E; full regression ผ่าน 195 tests / 485 assertions, Pint 304 files, Composer audit 0 advisory และ frontend build 879 modules

- ปิด LEGACY-02A: ตรวจ MIME/ขนาดภาพลงเวลาจาก bytes จริง, ใช้ UUID, บังคับ resource authorization สำหรับ private files และปิด `/storage` ที่ nginx
- ปิด LEGACY-02C: เพิ่ม account lockout, token expiry/refresh rotation, revoke token เมื่อระงับบัญชีหรือเปลี่ยนรหัสผ่าน, password reset ที่ไม่เปิดเผยบัญชี และ production session defaults ที่ปลอดภัย
- ปิด LEGACY-02D: บังคับรหัสผ่านทุกโหมดลงนาม, จำกัดผู้ลงนามตามผู้รับมอบหมาย, ผนึก document hash เพื่อตรวจ tampering และ throttle public verification
- ปิด LEGACY-02E: เพิ่ม security headers ที่ Laravel/nginx, ถอด global input sanitizer, กำจัด `v-html`, ซ่อน internal errors, throttle export/download และเพิ่ม automated route authorization audit

### LEGACY-01 Stabilization

- เพิ่ม automated backup พร้อม restore drill, queue worker และ scheduler
- เพิ่ม CI สำหรับ Pint, backend tests, fresh migration บน MySQL 8 และ frontend build
- เพิ่ม production debug guard, structured JSON logging และ request correlation ID
- แก้ LeaveController เมื่อผู้ใช้ไม่มี role และใช้ permission แทนชื่อ role
- เปลี่ยน `leaves.status` จาก ENUM เป็น `VARCHAR(20)` + CHECK เพื่อรองรับ `Voided`
- ลบ debug/test artifacts และอัปเดต Dompdf เป็น 3.1.6 เพื่อปิด security advisories
- เติมเมนู core 15 รายการด้วย idempotent seeder
- ปิด GATE-01 เมื่อ 2026-08-13: ใช้บัญชี `eleave_app` แบบจำกัดสิทธิ์, ตั้ง root password, ลบ remote root และยืนยัน backup หลัง restart
- เติมปีงบประมาณ 2567–2571 แบบ idempotent โดยกำหนดปี 2569 เป็นปีปัจจุบัน
- เติมวันหยุดราชการทั่วประเทศปี 2569 จำนวน 20 วัน (ไม่รวมวันหยุดเฉพาะ กทม. 16 ต.ค.) และลงทะเบียนแม่แบบใบลาใช้งานจริง 5 แบบจากเอกสารอ้างอิง
- แก้ Pint style issues 5 รายการใน 5 ไฟล์

## 2026-08-12 — Legacy Modernization Pack

- Added Legacy System Assessment
- Added Legacy Gap Analysis
- Added Legacy Migration Plan
- Added Legacy Upgrade Prompt
- Changed current phase to LEGACY-ASSESSMENT for existing-system upgrades
