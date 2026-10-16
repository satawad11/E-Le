# KNOWN LIMITATIONS / OPEN ITEMS

## เชิงออกแบบ (ตั้งแต่ต้น)

- รายละเอียดกฎหมาย/ระเบียบสิทธิการลาและเกณฑ์เลื่อนเงินเดือนต้องนำเข้าผ่าน Policy ที่ได้รับการตรวจสอบจากหน่วยงานก่อน production
- Connector เครื่องสแกนจริงต้องปรับตาม API/DB/file format ของผู้ผลิตที่ใช้งาน
- E-signature ที่มีผลตามกฎหมายอาจต้องเชื่อม provider/PKI เพิ่มเติม
- Multi-tenant แยกฐานข้อมูลไม่อยู่ใน initial scope; ออกแบบ organization-ready ก่อน

---

## Known issues ที่พบจริงจาก LEGACY-00 Freeze & Baseline (2026-08-12)

> บันทึกไว้เป็นสภาพ ณ `baseline/legacy-2026-08-12` (`6139212`)
> รายละเอียดและหลักฐานเต็มใน `docs/checkpoints/LEGACY-00-REPORT.md`

### K-01 · ฟีเจอร์ยกเลิกใบลาโดยคงประวัติ (void) ใช้งานไม่ได้

`leaves.status` เป็น `enum('Draft','Pending','Approved','Rejected','Cancelled')` ซึ่ง **ไม่มีค่า `Voided`**
แต่ `LeaveGovernanceController::void()` เขียนค่านี้ลงไป → `ERROR 1265 Data truncated` → HTTP 500 ทุกครั้ง

- **ไม่มีข้อมูลเสียหาย** — `STRICT_TRANS_TABLES` + `DB::transaction` ทำให้ rollback ทั้งก้อน (fail-safe)
- ยืนยันด้วยการทดลองจริงบนสำเนาที่ restore แล้วใน container แยก
- `status='Voided'` = 0 แถว → ไม่เคยมีใครใช้ฟีเจอร์นี้สำเร็จเลย
- ไม่มี test ครอบเมธอดนี้ จึงไม่มีใครจับได้
- ทางเลือกแก้ 3 แบบพร้อม risk อยู่ใน `docs/checkpoints/legacy-00/schema-baseline.md` §5

### K-02 · การแจ้งเตือนไม่ทำงาน — มีงานค้างในคิว 16 รายการ

`App\Notifications\LeaveRequested` × 16 ค้างใน `jobs` ตั้งแต่ 2026-08-09 ถึง 2026-08-11
`failed_jobs` = 0 → ไม่ใช่ล้มเหลว แต่ **ไม่เคยถูกหยิบไปทำ** เพราะไม่มี `queue:work` container

Scheduled command 4 ตัวใน `routes/console.php` (`backup:run`, `leave:governance`, purge, `carry-forward-leaves`)
ก็ไม่เคยรันด้วยเหตุผลเดียวกัน (ไม่มี `schedule:work`)

> ⚠️ เมื่อเปิด worker งานทั้ง 16 จะถูกส่งย้อนหลังทันที — ต้องตัดสินใจก่อนว่าจะส่งหรือล้างทิ้ง

### K-03 · ระบบ backup ที่ประกาศไว้ไม่มีอยู่จริง

`routes/console.php:5` เรียก `Schedule::command('backup:run')` แต่ `spatie/laravel-backup`
**ไม่อยู่ใน `composer.json`** → คำสั่งไม่มีอยู่จริง

LEGACY-00 สร้าง backup แบบ manual ที่ **พิสูจน์แล้วว่า restore ได้จริง** ไว้ที่
`docs/checkpoints/legacy-00/backups/` แต่ยังไม่มีระบบอัตโนมัติต่อเนื่อง

### K-04 · ข้อมูลตั้งต้นที่จำเป็นยังว่างเปล่า

| ตาราง | แถว | ผลกระทบ |
|---|---:|---|
| `fiscal_years` | 0 | `FiscalYear::current()` คืน null — ยอดวันลาคงเหลืออาจอิงปีที่ fallback ไม่ตรงกับที่ตั้งใจ |
| `holidays` | 0 | การคำนวณวันทำการไม่ตัดวันหยุดราชการ |
| `menus` | 0 | sidebar ที่ generate จากตารางนี้ว่างเปล่า |
| `document_templates` | 0 | การพิมพ์แบบฟอร์ม fallback ไป blade view |
| `saraban_document_types` | 0 | โมดูลสารบรรณสร้างหนังสือไม่ได้ |
| `work_calendar_exceptions` | 0 | ไม่มีข้อยกเว้นปฏิทินรายหน่วยงาน |

### K-05 · ผู้ใช้ 1 คนไม่มี `user_profiles`

`users` = 7 แถว แต่ `user_profiles` = 6 แถว → มีผู้ใช้ที่ `$user->profile` เป็น `null`
โค้ดหลายจุดเรียก `$user->profile->department_id` โดยไม่ป้องกัน null (เช่น `LeaveController::teamCalendar`,
`ApprovalController::pending`)

### K-06 · `App\Models\LeaveRequest` เป็น orphan model

ไม่มีตาราง `leave_requests` ในฐานข้อมูล — model นี้ไม่มีอะไรรองรับ

### K-07 · DDL บนตารางกลุ่ม Leave ช้ามาก

`ALTER TABLE` แต่ละคำสั่งบน `leaves`, `leave_approvals`, `leave_balances` ใช้เวลา 15–60 วินาที
(วัดจากการ migrate ใหม่จากศูนย์บน MySQL 8.0.46) — ไม่ใช่ deadlock แต่ช้าจาก I/O จริง

นี่คือคำอธิบายของเหตุการณ์ DDL timeout ในอดีต (`resume_interrupted_leave_hardening`)
**ทุก schema change ในเฟสถัดไปต้องประเมินเวลา/lock ล่วงหน้าและทำนอกเวลาราชการ**

### K-08 · Frontend build ช้าผิดปกติบน Docker Desktop / Windows

80% ของเวลา build อยู่ใน plugin `vite:vue` เพราะ bind mount ช้า — ไม่ใช่ปัญหาของโค้ด
แต่จะกระทบเวลา CI/CD ควรพิจารณา named volume หรือ copy source เข้า image แทน bind mount

### K-10 · 🔴 `APP_KEY` รั่วสู่อินเทอร์เน็ตผ่าน `/info.php` (SEC-020)

ผู้ใช้ยืนยัน 2026-08-12 ว่าระบบเข้าถึงได้จากอินเทอร์เน็ต · ตรวจด้วย HTTP จริงพบว่า
`GET /info.php` คืน 200 และแสดงค่า `APP_KEY` แบบ `base64:` เต็ม พร้อม `DB_PASSWORD`, `MAIL_PASSWORD`

`APP_KEY` คือกุญแจถอดรหัส `user_profiles.id_card_number`, `passport_number`, `users.phone`
และ secret ในตาราง `settings` ทั้งหมด → **การเข้ารหัสข้อมูลอ่อนไหวในระบบไม่มีผลบังคับอีกต่อไป**

**ห้ามหมุน `APP_KEY` เฉย ๆ** — ต้อง re-encrypt ข้อมูลเดิมก่อน มิฉะนั้นข้อมูลที่เข้ารหัสไว้จะสูญถาวร
ขั้นตอนที่ปลอดภัยอยู่ใน `docs/checkpoints/legacy-00/D4-EXPOSURE-VERIFICATION.md` §1

ยืนยันเพิ่มเติมว่าลายเซ็นบุคลากรดาวน์โหลดได้จริงโดยไม่ต้อง login ผ่าน **2 เส้นทาง**
(`/api/storage/{path}` และ `/storage/{path}` ผ่าน symlink) — การปิดต้องทำทั้งสองทาง

### K-09 · ช่องโหว่ความปลอดภัยที่ยังเปิดอยู่ทั้งหมด

CRITICAL 6 · HIGH 6 · MEDIUM 5 · LOW 2 — ดู `docs/LEGACY-SYSTEM-ASSESSMENT.md` §9
กำหนดแก้ในเฟส LEGACY-02

**บริบท:** ระบบยังไม่มีข้อมูลบุคลากรจริง (7 ผู้ใช้ทดสอบ) ซึ่งจำกัดความเสียหายในขณะนี้
**แต่ระบบเข้าถึงได้จากอินเทอร์เน็ตแล้ว (ยืนยัน 2026-08-12)** — จึงต้องปิด SEC-020 และ SEC-001
**ทันที** ไม่ใช่รอคิวของเฟส และต้องแก้ให้ครบทุกข้อก่อนนำเข้าข้อมูลบุคลากรจริง

---

## ข้อจำกัดของกระบวนการประเมิน

- `docs/LEGACY-SYSTEM-ASSESSMENT.md` และ `docs/LEGACY-GAP-ANALYSIS.md` รอบแรกจัดทำโดยไม่ได้รัน
  เครื่องมือจริง ทำให้มี 2 ข้อสรุปที่ผิดและถูกแก้แล้วหลัง LEGACY-00
  (schema drift ที่ไม่มีจริง และ test ที่คิดว่าแดงแต่จริง ๆ เขียว 86/86)
  — เอกสารทั้งสองฉบับมีหมายเหตุกำกับจุดที่แก้ไว้แล้ว
- Baseline commit + tag ยังอยู่บนเครื่อง local เท่านั้น ยังไม่ได้ push ขึ้น `origin`

---

## K-ORG-01 · ~~ผู้ถือ `edit_users` / `system.user.manage` มอบบทบาทหรือสิทธิ์ใดก็ได้ผ่านฟอร์มแก้ไขผู้ใช้~~ (พบ 2026-10-16 · **แก้แล้ว 2026-10-16**)

`POST /api/admin/users` และ `PUT /api/admin/users/{id}` รับ `role_id` (ทุกบทบาท รวม Super Admin) และ `custom_permissions` แล้ว `syncRoles()` / `syncPermissions()` โดยไม่ตรวจว่าผู้แก้มีสิทธิ์มอบสิ่งนั้นหรือไม่ — รวมถึงแก้บัญชีของตนเอง
LEGACY-04 ปิดทางผ่านหน้าจัดการสิทธิ์และการสร้าง role ไปแล้ว (`PrivilegeEscalationGuardTest`) แต่ทางนี้ยังเปิดอยู่

- **ผลกระทบตอนนี้:** มีเฉพาะ Super Admin ที่ถือสิทธิ์นี้ จึงยังไม่มีใครยกระดับได้จริง
- **ห้าม** มอบ `edit_users` หรือ `system.user.manage` ให้บทบาทอื่นจนกว่าจะแก้ — ORG-ACCESS-02 จึงใช้ `system.position.manage` แยกให้ HR Head
- แนวทางแก้: เปลี่ยน `role_id` / `custom_permissions` ได้เฉพาะผู้มี `admin.users.manage_permissions` และห้ามมอบบทบาทที่สูงกว่าของตนเอง พร้อม test

**สถานะ: แก้แล้ว (K-ORG-01-FIX)** — `UserController` ตรวจก่อนบันทึกทุกครั้ง:

- เปลี่ยน `role_id` / `custom_permissions` ได้เฉพาะผู้มี `admin.users.manage_permissions` (Super Admin ผ่าน Gate::before)
- ผู้อื่นสร้าง/นำเข้าผู้ใช้ได้เฉพาะบทบาทที่ไม่เกินของตน (ไม่ใช่ Super Admin และ permission ทั้งหมดของบทบาทนั้นตนถืออยู่แล้ว) และไม่มีสิทธิ์รายบุคคล
- ตอนแก้ไข ผู้อื่นเปลี่ยนบทบาทหรือสิทธิ์รายบุคคลไม่ได้ รวมบัญชีตนเอง (ส่งค่าเดิมกลับมาได้ ระบบไม่ sync) — ตอบ 403 พร้อมข้อความภาษาไทย นำเข้า CSV ข้ามแถวนั้นพร้อมเลขบรรทัด
- เพิ่ม: เปลี่ยนอีเมล/รหัสผ่านของผู้ที่มีสิทธิ์สูงกว่าตนไม่ได้ (กันการยึดบัญชี Super Admin ผ่านการตั้งรหัสผ่านใหม่)
- หน้าจัดการผู้ใช้ดึงบทบาทจาก `GET /api/admin/assignable-roles` แสดงบทบาทเป็นข้อความอ่านอย่างเดียวตอนแก้ไข และไม่ส่ง `custom_permissions` เมื่อไม่มีสิทธิ์
- ทดสอบใน `PrivilegeEscalationGuardTest` (store / update / import / ตนเอง / ผู้อื่น / แก้ชื่อและทะเบียนประวัติยังได้)

ตอนนี้มอบ `edit_users` ให้ HR Head ได้โดยไม่เปิดทางยกระดับสิทธิ์ — ยังไม่ได้มอบ ต้องให้เจ้าของระบบตัดสินใจ
