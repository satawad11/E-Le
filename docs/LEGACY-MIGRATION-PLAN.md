# LEGACY MIGRATION & MODERNIZATION PLAN

> แผนอัปเกรดระบบเดิมเข้าสู่ E-la Target Architecture แบบ Incremental และ Rollback ได้
>
> **สถานะ:** กรอกข้อมูลจริงแล้ว — รอบที่ 1 | **วันที่:** 2026-08-12
> **ระบบเป้าหมาย:** DMTC E-Leave / Attendance / Saraban (Laravel 13 + Vue 3 + MySQL 8)
> **อ้างอิง:** `docs/LEGACY-SYSTEM-ASSESSMENT.md`, `docs/LEGACY-GAP-ANALYSIS.md`
> **แผนนี้ยังไม่ได้รับอนุมัติ — ห้ามเริ่มแก้ Source Code**

---

## 1. หลักการ

1. ไม่ Rewrite ทั้งระบบโดยไม่มีเหตุผล
2. Reuse สิ่งที่ปลอดภัยและ maintainable — โดยเฉพาะ **Leave Policy Engine, PDPA layer, Government report suite, Evidence integrity** ซึ่งประเมินแล้วว่ามีคุณภาพสูง
3. Refactor แบบ Incremental
4. Additive database migration first
5. Backward compatible ก่อน Cutover
6. รักษาข้อมูลเดิมทั้งหมด
7. ทุก Phase มี Verification + Rollback
8. ห้ามทำ Feature expansion ก่อนแก้ P0/P1

### 1.1 หลักการเพิ่มเติมที่มาจากผลการประเมินจริง

9. **ห้ามแตะ schema ใด ๆ ก่อน LEGACY-00 ยืนยันว่า schema production ตรงกับ migration** — มีหลักฐานว่าเคยมี DDL timeout กลางคัน (`2026_08_08_100050_resume_interrupted_leave_hardening.php`)
10. **ห้ามเริ่มงานใด ๆ ก่อนมี backup ที่กู้คืนได้จริง** — ปัจจุบันไม่มี backup ทำงานเลย (`spatie/laravel-backup` ไม่ได้ติดตั้ง)
11. **Security fix (LEGACY-02) ต้องมาก่อน architecture work (LEGACY-03+)** — เพราะข้อมูลกำลังรั่วอยู่ ณ ปัจจุบัน
12. **Test ต้องเขียวก่อน refactor** — ปัจจุบันมีร่องรอยว่าประมาณครึ่งหนึ่งของ suite ไม่ผ่าน
13. **แก้ทีละโมดูล ไม่แก้ข้ามโมดูลใน commit เดียว** — เพื่อให้ rollback ได้จริง
14. **Test นำการแก้เสมอ** สำหรับงาน security: เขียน test ที่ล้ม (พิสูจน์ช่องโหว่) ก่อน แล้วจึงแก้ให้ผ่าน

---

## 2. Data Preservation

### 2.1 ข้อมูลที่ต้องรักษา (ตารางจริงในระบบนี้)

| กลุ่ม | ตาราง |
|---|---|
| Users & Personnel | `users`, `user_profiles` (มี field เข้ารหัส `id_card_number`, `passport_number`, `phone`), `personnel_types` |
| Organization | `departments` |
| Authorization | `roles` (legacy), `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`, `users.role_id`, `users.custom_permissions` |
| Leave | `leaves` (**รวม soft-deleted**), `leave_types`, `leave_approvals`, `leave_balances`, `leave_policies`, `leave_adjustments`, `leave_evidences`, `leave_audit_logs`, `leave_action_logs`, `leave_document_sequences`, `approval_steps` |
| Attendance / OT | `attendances` (รวมคอลัมน์ OT) |
| Saraban | `saraban_documents` (**รวม soft-deleted**), `saraban_document_types`, `_sequences`, `_incoming`, `_outgoing`, `_attachments`, `_receivers`, `_user_states`, `_workflow_templates`, `_workflow_steps`, `_signatures`, `_circulars`, `_circular_recipients`, `saraban_audit_logs` |
| Compliance | `personal_data_access_logs` |
| Config | `settings`, `fiscal_years`, `holidays`, `work_calendar_exceptions`, `menus`, `document_templates`, `user_delegations` |
| Platform | `notifications`, `jobs` (มี job ค้างที่ยังไม่ถูกประมวลผล — **ห้ามล้าง**), `personal_access_tokens` |

### 2.2 ไฟล์ที่ต้องรักษา

| ที่เก็บ | เนื้อหา | หมายเหตุ |
|---|---|---|
| `storage/app/private/private/leave-evidences/` | ใบรับรองแพทย์และหลักฐานประกอบการลา | มี `sha256` ใน DB → verify ได้หลังย้าย |
| `storage/app/public/signatures/` | ลายเซ็นบุคลากร | **ต้องย้ายไป private ใน LEGACY-02** |
| `storage/app/public/attendances/` | ภาพเซลฟี่ลงเวลา | **ต้องย้ายไป private ใน LEGACY-02** |
| `storage/app/public/saraban/attachments/` | ไฟล์แนบหนังสือราชการ | **ต้องย้ายไป private ใน LEGACY-02** |

### 2.3 ก่อน Migration ทุกครั้งต้องมี

- [ ] Full logical backup (`mysqldump --single-transaction --routines --triggers`) + ตรวจว่า restore ได้จริงบน instance แยก
- [ ] Backup ของ `storage/app/` ทั้งหมด (tar + checksum)
- [ ] Row count baseline ทุกตารางใน §2.1 (บันทึกลง `docs/checkpoints/`)
- [ ] Checksum ของตารางวิกฤต: `leaves`, `leave_approvals`, `leave_balances`, `attendances`, `saraban_documents`
- [ ] `SHOW CREATE TABLE` ของทุกตาราง เก็บเป็น schema snapshot
- [ ] Rollback script ที่เขียนไว้ล่วงหน้าและทดสอบบน staging แล้ว

---

## 3. Migration Phases

> **ลำดับนี้บังคับ** — ห้ามข้ามหรือสลับ Phase P0 ไปหลัง Phase P1

### Phase LEGACY-00 — Freeze & Baseline `[P0 · บล็อกทุกอย่าง]`

**เป้าหมาย:** รู้ให้แน่ว่าระบบตอนนี้อยู่สภาพใด และกู้คืนได้จริง

| # | งาน | เหตุผล (อ้าง Gap) |
|---|---|---|
| 00-1 | Commit ชุดเอกสาร E-la + working tree 60+ ไฟล์ที่ค้างอยู่ เป็น commit ที่มีข้อความชัดเจน; สร้าง tag `baseline/legacy-2026-08-12` | §14.1 — ตอนนี้ไม่มีจุดอ้างอิงที่ย้อนกลับได้ |
| 00-2 | **ยืนยัน schema จริง**: รัน `php artisan migrate:status`; dump `information_schema` ของ DB production; diff กับ schema ที่ migration ควรสร้าง; บันทึกผลลง `docs/checkpoints/schema-baseline.md` | **DB-RISK-006** |
| 00-3 | ตรวจเฉพาะจุด: `SHOW CREATE TABLE leaves` — enum `status` รองรับ `'Voided'` หรือไม่ | **DB-RISK-007** — ถ้าไม่รองรับ ข้อมูลอาจเสียหายอยู่แล้ว |
| 00-4 | **ติดตั้ง backup ที่ทำงานจริง**: `composer require spatie/laravel-backup` + config + เพิ่ม scheduler container + รัน backup แรก + **ทดสอบ restore บน instance แยก** | **BACKUP-001/002** — `Schedule::command('backup:run')` มีอยู่แต่คำสั่งไม่มีจริง |
| 00-5 | Backup `storage/app/` + checksum | §2.2 |
| 00-6 | บันทึก row count baseline ทุกตาราง | §2.3 |
| 00-7 | **รัน `php artisan test` จริง** และบันทึกผลลง `docs/checkpoints/test-baseline.md` — ยืนยันหรือหักล้างตัวเลข 42 defect จาก `.phpunit.result.cache` | **TEST-002** |
| 00-8 | รัน `npm run build` ฝั่ง frontend และบันทึกผล | TEST-004 |
| 00-9 | บันทึก known issues ทั้งหมดจาก Assessment ลง `docs/KNOWN-LIMITATIONS.md` | — |
| 00-10 | ประกาศ **schema freeze** — ห้ามเพิ่ม migration ใด ๆ ที่ไม่อยู่ในแผนนี้ | หลักการข้อ 9 |

**Exit Criteria**
- [ ] มี backup ที่ **restore สำเร็จแล้วจริง** (ไม่ใช่แค่มีไฟล์)
- [ ] schema production ได้รับการยืนยันว่าตรง/ไม่ตรงกับ migration พร้อมรายการความต่างที่ชัดเจน
- [ ] มีผล test/build จริงบันทึกไว้เป็น baseline
- [ ] มี git tag ที่ย้อนกลับได้

**Rollback:** ไม่มีการเปลี่ยนแปลงระบบใน Phase นี้ (อ่านอย่างเดียว + เพิ่ม backup)

---

### Phase LEGACY-01 — Stabilization `[P0/P1]`

**เป้าหมาย:** ให้มีตาข่ายนิรภัยก่อนแตะโค้ดจริง และกำจัดสิ่งที่อันตรายโดยไม่ต้องออกแบบอะไรใหม่

| # | งาน | เหตุผล |
|---|---|---|
| 01-1 | **ลบไฟล์อันตราย**: `backend/public/info.php`, `backend/public/test.php`, `backend/public/test_db.php` | SEC-010 — `phpinfo()` + DB credentials เปิดจากเว็บ |
| 01-2 | **ลบ route `GET /fix-leaves`** ใน `routes/web.php` (มี artisan command `FixLeaveDocumentNumbers` ทำงานเดียวกันอยู่แล้ว) | SEC-011 |
| 01-3 | หมุน DB credentials + ตั้งรหัส MySQL (เลิก `MYSQL_ALLOW_EMPTY_PASSWORD`) + สร้าง user เฉพาะแอปแทน root | SEC-015 |
| 01-4 | ย้ายไฟล์ dev ออกจาก repo: `debug.php`, `fix.php`, `fix_hash.php`, `sqlite_test.php`, `seed_balances.php`, `sync_test_accounts.php`, `test_api_call.php`, `test_login.php`, `backend/eleave` (SQLite); เพิ่ม `.gitignore` ที่ root | §14.1, DB-RISK-011 |
| 01-5 | **ทำ test suite ให้เขียว** — แก้เฉพาะ test/สภาพแวดล้อม ไม่เปลี่ยน business logic; ถ้า test เผยบั๊กจริงให้แยกเป็น issue และแก้ในข้อถัดไป | TEST-002 |
| 01-6 | เพิ่ม CI pipeline: lint (`pint`) + test + **migration test บน MySQL 8** + `npm run build` | TEST-003, TEST-004 |
| 01-7 | ตั้ง env validation ตอน boot (fail fast ถ้า `APP_DEBUG=true` ขณะ `APP_ENV=production`) | SEC-017 |
| 01-8 | เพิ่ม structured logging + request correlation ID middleware | OBS-001 |
| 01-9 | แก้บั๊กที่ทำให้ crash ได้: `LeaveController::update/destroy` ใช้ `$user->role->name` ซึ่งจะ error เมื่อ `role_id` เป็น null | LEAVE-006 |

**Exit Criteria**
- [ ] `php artisan test` เขียว 100% และรันใน CI ได้
- [ ] Migration test ผ่านบน MySQL 8
- [ ] ไม่มีไฟล์ dev/debug ใน repo และไม่มี endpoint สาธารณะที่เขียน DB
- [ ] `npm run build` ผ่านใน CI

**Rollback:** revert commit — ไม่มีการเปลี่ยน schema

---

### Phase LEGACY-02 — Security Hardening `[P0 · ห้ามข้าม ห้ามทำหลัง feature ใด ๆ]`

**เป้าหมาย:** ปิดช่องโหว่ที่กำลังทำให้ข้อมูลรั่วอยู่ ณ ปัจจุบัน

> **วิธีทำงานบังคับ:** ทุกข้อในตารางนี้ต้อง **เขียน test ที่ล้มก่อน** (พิสูจน์ช่องโหว่) แล้วจึงแก้ให้ผ่าน

#### 02-A · File Security `[P0]`

| # | งาน | Gap |
|---|---|---|
| 02-A1 | เขียน test: ผู้ใช้ที่ไม่ล็อกอินเรียก `/api/storage/signatures/*` แล้วต้องได้ 401/404 | SEC-001 |
| 02-A2 | สร้าง endpoint ดาวน์โหลดที่ตรวจสิทธิ์ (`FileAccessController`) แบบเดียวกับ `LeaveEvidenceController::download` ที่ทำถูกอยู่แล้ว | SEC-001 |
| 02-A3 | ย้ายไฟล์จาก `storage/app/public/{signatures,attendances,saraban}` ไป `storage/app/private/` + backfill path ใน DB (`user_profiles.signature_path`, `leaves.signature_path`, `attendances.check_in_image`/`check_out_image`, `saraban_document_attachments.path`) — **คงไฟล์เดิมไว้ก่อน ยังไม่ลบ** | SEC-001 |
| 02-A4 | แก้จุดอัปโหลดให้เขียนลง private + ชื่อไฟล์สุ่ม: `ProfileController.php:63,73`, `LeaveController.php:270`, `AttendanceController.php:63-73`, `Api/Saraban/DocumentController.php:296,306`, `Api/Saraban/SignatureController.php:66-70` | SEC-001 |
| 02-A5 | **ลบ route `GET /api/storage/{path}`** และลบ `location /storage` ใน `docker/nginx/default.conf` | SEC-001 |
| 02-A6 | เพิ่ม upload validation ที่ `AttendanceController::checkIn`: whitelist นามสกุล + ตรวจ MIME จริงด้วย `finfo` + จำกัดขนาด (ใช้แพทเทิร์นเดียวกับ `LeaveController.php:267` ที่ทำถูกแล้ว) | SEC-002 |
| 02-A7 | ตั้ง nginx `location ^~ /storage` + ปิดการรัน PHP ในโฟลเดอร์อัปโหลด (defense in depth) | SEC-002 |

**Verification:** สคริปต์ทดสอบว่าไม่มีไฟล์ใน `storage/app/public` ที่เข้าถึงได้โดยไม่ยืนยันตัวตน + ตรวจ `sha256` ของไฟล์หลังย้ายตรงกับก่อนย้าย
**Rollback:** คืน route เดิมชั่วคราว (ไฟล์ต้นฉบับยังอยู่ที่เดิมเพราะ 02-A3 เป็นการ copy ไม่ใช่ move จนกว่าจะ verify)

#### 02-B · Authorization & IDOR `[P0]`

| # | งาน | Gap |
|---|---|---|
| 02-B1 | สร้าง `app/Policies/` (ยังไม่มีเลยทั้งโปรเจกต์): `LeavePolicy`, `AttendancePolicy`, `SarabanDocumentPolicy`, `UserPolicy` | CORE-AUTHZ-005 |
| 02-B2 | เขียน IDOR test ชุดแรก: ผู้ใช้ A เรียก `/api/leaves/{ของ B}/pdf`, `/word`, `/hr-verify` → ต้อง 403 | SEC-003, SEC-004 |
| 02-B3 | เพิ่ม `$this->authorize()` ที่ `LeaveController::exportPdf`, `exportWord`, `hrVerify` (+ บังคับ `can:leave.approval.hr` ที่ route) | SEC-003, SEC-004 |
| 02-B4 | **ผูก middleware `CanViewClassifiedDocument`** เข้ากับ route Saraban (ปัจจุบันเป็น dead code) + เพิ่ม Policy ตาม creator/receivers/สิทธิ์สารบรรณ ที่ `DocumentController::show` | SEC-005 |
| 02-B5 | บังคับ authorization ที่ `DocumentController::destroy`, `destroyMultiple`, `update` | SEC-006 |
| 02-B6 | บังคับ authorization ทุก transition ใน `Api/Saraban/WorkflowController` (submit/approve/reject) | CORE-WF-002 |
| 02-B7 | ย้าย 7 endpoint export attendance กลับเข้า `auth:sanctum` + `can:attendance.view_all`; แทน token-in-query ด้วย signed URL อายุสั้น | SEC-009, CORE-AUTH-002 |
| 02-B8 | **Route authorization audit**: เขียน test ที่วนทุก route แล้วล้มถ้ามี route ที่ไม่มี authorization middleware และไม่อยู่ใน allowlist ที่ประกาศไว้ | CORE-AUTHZ-001 |

**Verification:** IDOR test ครบทุก resource ผ่าน + route audit test ผ่าน
**Rollback:** revert ทีละโมดูล (แยก commit ต่อ controller)

#### 02-C · Authentication & Session `[P0]`

| # | งาน | Gap |
|---|---|---|
| 02-C1 | ตั้ง `sanctum.expiration` (ค่าที่ต้องถามผู้ใช้ — ดู Open Question #7) + refresh flow ฝั่ง frontend | SEC-012 |
| 02-C2 | เพิกถอน token ทั้งหมดเมื่อปิดบัญชี (`Admin\UserController::toggleStatus` → `$user->tokens()->delete()`) และเมื่อเปลี่ยนรหัสผ่าน | CORE-AUTH-001 |
| 02-C3 | เพิ่ม account lockout หลังพยายามล็อกอินผิดหลายครั้ง (นอกเหนือจาก IP throttle ที่มีอยู่) | CORE-AUTH-001 |
| 02-C4 | ตั้ง `SESSION_SECURE_COOKIE=true` + `SESSION_ENCRYPT=true` สำหรับ production env | CORE-AUTH-001 |
| 02-C5 | เพิ่มขั้นตอน password reset (ตาราง `password_reset_tokens` มีอยู่แล้วแต่ไม่มี endpoint) | CORE-AUTH-001 |

#### 02-D · E-Signature Integrity `[P0]`

| # | งาน | Gap |
|---|---|---|
| 02-D1 | บังคับยืนยันรหัสผ่านทุก `mode` ใน `SignatureController::sign` (เลิกให้ client ควบคุมว่าจะตรวจหรือไม่) | SEC-007 |
| 02-D2 | ตัดเงื่อนไข `$isCreator` และเพิ่มการตรวจว่าผู้ลงนามเป็นผู้รับผิดชอบ workflow step ปัจจุบัน | SEC-007 |
| 02-D3 | `verify()` ต้องคำนวณ hash ของเอกสารใหม่แล้วเทียบกับที่ผนึกไว้ (ใช้แพทเทิร์นเดียวกับ `LeaveVerificationService` ที่ทำถูกอยู่แล้ว) แทนการคืน `is_valid: true` แบบ hard-code | SEC-008 |
| 02-D4 | เพิ่ม rate limit บน endpoint verify สาธารณะ (Leave ทำแล้วที่ 30/min — Saraban ยังไม่มี) | SEC-019 |

#### 02-E · Hardening ทั่วไป `[P1/P2]`

| # | งาน | Gap |
|---|---|---|
| 02-E1 | ถอด `SanitizeInput` middleware; แทนด้วย validation ที่ input + escape ที่ output; ตรวจ `v-html` 2 จุดใน frontend | SEC-013 |
| 02-E2 | เพิ่ม security headers middleware + nginx (CSP, HSTS, X-Frame-Options, X-Content-Type-Options) | SEC-014 |
| 02-E3 | แปลง error ภายในเป็นข้อความไทยมาตรฐาน (เลิกส่ง `$e->getMessage()` ให้ผู้ใช้) | UI-001 |
| 02-E4 | เพิ่ม throttle เฉพาะ export/download | SEC-019 |

**Exit Criteria ของ LEGACY-02 ทั้ง Phase**
- [ ] SEC-001 ถึง SEC-012 ปิดครบ และมี test ยืนยันทุกข้อ
- [ ] ไม่มีไฟล์บุคคลใดเข้าถึงได้โดยไม่ยืนยันตัวตน (พิสูจน์ด้วยสคริปต์)
- [ ] IDOR test ครอบคลุมทุก resource
- [ ] Route authorization audit test ผ่าน
- [ ] ไม่มี regression ใน test เดิม

**Rollback Trigger:** ถ้าการย้ายไฟล์ทำให้เอกสาร/PDF เดิมเปิดไม่ได้ → คืน path เดิมทันที (ไฟล์ต้นฉบับยังไม่ถูกลบ)

---

### Phase LEGACY-03 — Core Data Foundation `[P1]`

**เป้าหมาย:** สร้างรากฐานข้อมูลที่ ABAC และ workflow ต้องใช้ — **additive ล้วน ห้าม drop/rename**

| # | งาน | Gap | รูปแบบ |
|---|---|---|---|
| 03-1 | สร้าง `org_units` (parent_id, code, name, type, effective_from/to, is_active) + `department_org_unit_map` | CORE-ORG-001 | ADD |
| 03-2 | Backfill: ทุก `departments` → `org_units` ระดับ 1 ใต้ root ของวิทยาลัย | CORE-ORG-001 | BACKFILL |
| 03-3 | เพิ่ม `users.org_unit_id` (nullable) + backfill จาก `department_id`; **คง `department_id` ไว้ทั้งหมด** | CORE-ORG-001 | ADD + dual-write |
| 03-4 | สร้าง `personnel_assignments` (user, org_unit, personnel_type, position, effective_from/to) + backfill 1 แถวจากค่าปัจจุบันของ `users` | CORE-PER-002 | ADD |
| 03-5 | เพิ่มคอลัมน์ `uuid` ให้ทุกตารางที่เป็น public resource (`users`, `leaves`, `attendances`, `saraban_documents`, …) + backfill + unique index | CORE-ID-001 | ADD |
| 03-6 | สร้าง `audit_logs` กลาง (actor, effective_actor, action, resource_type, resource_id, before, after, reason, ip, user_agent, correlation_id, created_at) — append-oriented | CORE-AUDIT-001 | ADD |
| 03-7 | Dual-write: เขียนลง `audit_logs` ควบคู่กับ 4 ตารางเดิม; เพิ่ม audit ในจุดที่ยังไม่มีเลย (users, roles, permissions, settings, attendance) | CORE-AUDIT-001, SEC-016 | DUAL-WRITE |
| 03-8 | สร้าง `document_template_versions` + backfill เนื้อหาปัจจุบันเป็น v1 + เพิ่ม `leaves.document_template_version_id` (nullable) | LEAVE-003, DB-RISK-004 | ADD |
| 03-9 | แปลง `leaves.status` / `leave_approvals.status` จาก MySQL `enum` เป็น `varchar` + CHECK constraint — **เฉพาะเมื่อ 00-3 ยืนยันว่าจำเป็น** | DB-RISK-007 | ALTER (ต้องมี maintenance window) |

**Verification:** row count `org_units` = `departments`; ทุก user มี `org_unit_id`; ทุก `leaves` เดิมมี template version = v1; `audit_logs` มีจำนวน entry ≥ ผลรวมของ 4 ตารางเดิมนับจากวันที่เริ่ม dual-write
**Rollback:** คอลัมน์/ตารางใหม่ทั้งหมดเป็น nullable และไม่มีโค้ดอ่านจริงจนกว่าจะ switch reads → drop ได้อย่างปลอดภัย (ยกเว้น 03-9 ที่ต้องมี rollback script เฉพาะ)

---

### Phase LEGACY-04 — RBAC + ABAC `[P1]`

**เป้าหมาย:** เลิก role hard-code 31 จุด และรวมสิทธิ์ให้เหลือแหล่งเดียว

| # | งาน | Gap |
|---|---|---|
| 04-1 | สร้าง canonical permission set `MODULE.RESOURCE.ACTION` + ตาราง `permission_aliases` map ชื่อเก่า (3 generation) → ชื่อใหม่ | CORE-AUTHZ-002 |
| 04-2 | สร้าง `PermissionEvaluator` service: deny > scoped allow > role allow > default deny | CORE-AUTHZ-004 |
| 04-3 | เพิ่ม `permission_scopes` (SELF / UNIT / DESCENDANTS / ORGANIZATION / ASSIGNED / CUSTOM) ผูกกับ `org_units` จาก LEGACY-03 | CORE-ORG-002 |
| 04-4 | **แทน `hasRole()`/`hasAnyRole()` ทีละโมดูล** ตามลำดับ: Leave → Attendance → Saraban → Menu/Dashboard/Report; แต่ละโมดูลมี test ก่อนแก้ | CORE-AUTHZ-003 |
| 04-5 | แก้ `Gate::before` ให้ Super Admin ยังผ่านได้แต่ **ถูก deny ที่ระบุชัดเจนปฏิเสธได้** | CORE-AUTHZ-004 |
| 04-6 | รวม `users.custom_permissions` (json) เข้า Spatie `model_has_permissions` + backfill + verify แล้วเลิกอ่านคอลัมน์เดิม | DB-RISK-009 |
| 04-7 | สร้าง compat adapter สำหรับ `users.role_id` → Spatie roles; แก้ `ApprovalController::pending` ที่รวม id ข้าม namespace; **ยังไม่ลบคอลัมน์** | DB-RISK-002, LEAVE-006 |
| 04-8 | แก้ frontend: ลบ role-name check ทั้งหมดใน `stores/auth.js`; ใช้เฉพาะ permission ที่ backend ส่งมา; ลบ alias map 11 ชุด | CORE-AUTHZ-003 |
| 04-9 | เขียน authorization test matrix: role × scope × action ตาม `docs/10-TESTING-STANDARDS.md` | TEST-001 |

**Verification:** grep `hasRole|hasAnyRole` ใน `app/` = 0 hit (ยกเว้น compat adapter ที่ประกาศไว้); test matrix ผ่านครบ; ผู้ใช้ทุกคนได้สิทธิ์เท่าเดิมหรือแคบลง ไม่มีใครได้สิทธิ์เพิ่มโดยไม่ตั้งใจ (เทียบ snapshot สิทธิ์ก่อน/หลัง)
**Rollback:** feature flag `USE_NEW_PERMISSION_ENGINE` — สลับกลับไปใช้ตรรกะเดิมได้ทันที

---

### Phase LEGACY-05 — Workflow Engine `[P1]`

**เป้าหมาย:** แทน `approval_steps` + `leaves.approval_level` ด้วย engine ที่ versioned และ configurable

| # | งาน | Gap |
|---|---|---|
| 05-1 | สร้าง `workflow_definitions`, `workflow_versions`, `workflow_steps`, `workflow_transitions`, `workflow_instances`, `workflow_tasks`, `workflow_actions`, `workflow_delegations` ตาม `docs/07` | CORE-WF-001 |
| 05-2 | แปลง `approval_steps` ปัจจุบันเป็น workflow definition v1 (สายอนุมัติใบลาแบบเดิม) | CORE-WF-001 |
| 05-3 | **Shadow run**: ให้ engine ใหม่คำนวณขั้นตอนคู่ขนานกับตรรกะเดิม แล้ว log ความต่าง โดยยังใช้ผลของตรรกะเดิม | หลักการข้อ 7 |
| 05-4 | เมื่อ shadow run ตรงกัน 100% เป็นเวลาที่ตกลงกัน → switch reads แล้ว switch writes | Cutover §6 |
| 05-5 | รวม Saraban workflow เข้า engine เดียวกัน + pin version ที่ instance | CORE-WF-002 |
| 05-6 | เพิ่ม parallel / conditional / return / escalation / timeout / acting approver | CORE-WF-001 |
| 05-7 | **เก็บ `leave_approvals` และ `approval_steps` เดิมไว้ทั้งหมดในฐานะข้อมูลอ่านอย่างเดียว** — ห้ามลบ | §2.1 |

**Verification:** ใบลาที่ pending อยู่ทั้งหมดเดินต่อได้ถูกต้อง; ประวัติการอนุมัติเดิมแสดงผลเหมือนเดิมทุกรายการ; instance ที่กำลังรันไม่เปลี่ยน semantics เมื่อแก้ workflow definition
**Rollback Trigger:** approval history mismatch แม้แต่รายการเดียว → กลับไปใช้ตรรกะเดิมทันที

---

### ▶ CORE CHECKPOINT `[บังคับหยุด]`

**เงื่อนไขผ่าน**
- [ ] P0 gaps = 0
- [ ] P1 gaps = 0
- [ ] Authorization test matrix + IDOR test ผ่านครบ
- [ ] Backup + restore drill สำเร็จ
- [ ] Data reconciliation ผ่าน (row count + checksum ตรงกับ baseline บวกด้วยรายการใหม่ที่อธิบายได้)
- [ ] `docs/TRACEABILITY-MATRIX.md` อัปเดตครบ
- [ ] สร้าง `CORE_CHECKPOINT_REPORT.md` และได้รับอนุมัติ

**ห้ามเริ่ม Phase 06 ขึ้นไปก่อน checkpoint ผ่าน**

---

### Phase LEGACY-06 — Time Foundation `[P2]`

| # | งาน | Gap |
|---|---|---|
| 06-1 | สร้าง `work_schedules` + `work_schedule_assignments` แบบ effective-dated (รองรับตารางรายวัน/กะ) | TIME-SCH-001 |
| 06-2 | Backfill จาก `users.work_start_time`/`work_end_time` + `default_work_start_time` เป็น schedule เริ่มต้น | TIME-SCH-001 |
| 06-3 | เพิ่ม grace period ใน policy (ปัจจุบันสายทันทีที่เกิน 1 วินาที) | TIME-SCH-001 |
| 06-4 | เชื่อม `holidays` + `work_calendar_exceptions` ที่มีอยู่แล้วเข้ากับ schedule engine (ไม่ต้องสร้างใหม่ — ของเดิมดีอยู่แล้ว) | TIME-CAL-001 (PASS) |

---

### Phase LEGACY-07 — Leave Modernization `[P2]`

| # | งาน | Gap |
|---|---|---|
| 07-1 | Switch reads ของ template ไปที่ `document_template_versions` และ pin version ตอนอนุมัติ | LEAVE-003 |
| 07-2 | แตก `LeaveController` (888 บรรทัด) เป็น service + Policy + FormRequest; ลบตรรกะซ้ำระหว่าง `exportPdf`/`exportWord` | REFACTOR |
| 07-3 | ยืนยันด้วย test ว่ารายงานนับ "จำนวนครั้ง" แยกจาก "จำนวนวัน" ถูกต้องทุกรายงาน | LEAVE-001 |
| 07-4 | ขยาย `LeaveEvidenceController::authorizeAccess` ให้ผู้อนุมัติในสายงานปัจจุบันเข้าถึงหลักฐานได้ | LEAVE-004 |
| 07-5 | ยืนยันความสอดคล้องของฐานปีระหว่าง `leave_document_sequences` (ตาม policy `period_basis`) กับ Saraban (ปีงบประมาณ) — ดู Open Question #6 | SARABAN-001 |

> **ห้ามแตะ `leave_policies` schema** — เป็นส่วนที่ประเมินแล้วว่าถูกต้อง (PASS) ให้ต่อยอดเท่านั้น

---

### Phase LEGACY-08 — Attendance Integration `[P2]`

**⚠️ บล็อกอยู่:** ต้องได้คำตอบ Open Question #1 และ #2 (ยี่ห้อเครื่องสแกน + วิธีส่งข้อมูล + identifier) ก่อนออกแบบ connector

**โครงสร้างเป้าหมาย**

```text
Face/Fingerprint Device
        ↓
Vendor Management System        ← UNKNOWN: ต้องได้ข้อมูลจากผู้ใช้
        ↓
Connector (adapter pattern)     ← REST / webhook / DB read-only / SFTP / file
        ↓
attendance_raw_events           ← เก็บ payload ดิบ + dedup key
        ↓
Normalization + identity/device mapping
        ↓
Attendance Engine               ← ตัดสินสถานะตาม work_schedules + policy
        ↓
attendances                     ← ผลลัพธ์ (ตารางเดิม)
```

| # | งาน | Gap |
|---|---|---|
| 08-1 | สร้าง `attendance_sources` (registry: ชื่อ, ประเภท, config, health state, cursor) | ATT-INT-001 |
| 08-2 | สร้าง `attendance_devices` (device id ของ vendor ↔ สถานที่/หน่วยงาน) | ATT-INT-001 |
| 08-3 | สร้าง `attendance_identity_map` (vendor employee id ↔ `users.id`) | ATT-INT-001 |
| 08-4 | สร้าง `attendance_raw_events` (source, device, raw payload JSON, dedup key unique, processed_at, error) | ATT-INT-002 |
| 08-5 | สร้าง Connector adapter ตัวแรกตาม vendor จริง + timeout/retry/cursor/health/audit ตาม `docs/15` | ATT-INT-001 |
| 08-6 | สร้าง Attendance Engine ที่คำนวณ `attendances` จาก raw events + work schedule (ADR-003) | ATT-INT-002 |
| 08-7 | **ปลดล็อก `unique(user_id, date)` เฉพาะเมื่อ engine ใหม่ verify แล้ว** — ระหว่างนี้ให้ engine เขียนผลลัพธ์วันละแถวเหมือนเดิม | DB-RISK-005 |
| 08-8 | Reconciliation job + รายงานความต่างระหว่าง vendor กับระบบกลาง | ATT-INT-002 |
| 08-9 | เพิ่ม correction workflow สำหรับแก้ข้อมูลลงเวลา (ผ่าน workflow engine จาก LEGACY-05) | ATT-001 |
| 08-10 | แตก `AttendanceController` (1,137 บรรทัด) เป็น service | REFACTOR |

**Verification:** event ซ้ำจาก vendor ไม่สร้าง attendance ซ้ำ (ACCEPTANCE-CRITERIA); ข้อมูล `attendances` เดิมทุกแถวยังอยู่ครบและไม่ถูกเขียนทับ

---

### Phase LEGACY-09 — Overtime `[P2]`

| # | งาน | Gap |
|---|---|---|
| 09-1 | สร้าง `ot_orders` (คำสั่งให้ปฏิบัติงานนอกเวลา), `ot_assignments`, `ot_time_records` (approved / actual / certified) | OT-001 |
| 09-2 | Backfill: คอลัมน์ OT เดิมใน `attendances` → `ot_time_records` ในฐานะ actual time (**คงคอลัมน์เดิมไว้**) | OT-001 |
| 09-3 | เพิ่ม overlap detection กับใบลาและ OT อื่น | OT-001 |
| 09-4 | เชื่อมการอนุมัติ/รับรอง OT เข้า workflow engine | OT-001 |
| 09-5 | รายงาน OT ที่แยก approved/actual/certified ชัดเจน | OT-001 |

---

### Phase LEGACY-10 — Notification Center `[P1 บางส่วน / P2]`

> **ส่วน P1 (queue worker) ควรทำตั้งแต่ LEGACY-01** เพราะตอนนี้แจ้งเตือนไม่ทำงานเลย

| # | งาน | Gap | Priority |
|---|---|---|---|
| 10-1 | เพิ่ม service `worker` (`queue:work`) และ `scheduler` (`schedule:work`) ใน docker-compose | NOTIFY-004, DEPLOY-003 | **P1 — ทำใน LEGACY-01** |
| 10-2 | ตรวจ `jobs` table ว่ามี job ค้างที่ยังไม่ถูกส่งหรือไม่ และตัดสินใจว่าจะส่งย้อนหลังหรือไม่ | NOTIFY-004 | **P1** |
| 10-3 | สร้าง Notification Center: templates (versioned), preferences, mandatory notices, delivery log | NOTIFY-001/002/003 | P2 |
| 10-4 | รวม `TelegramService` + `TelegramNotificationService` เป็น provider เดียว; ทำ provider pattern สำหรับ In-App / LINE OA / Telegram / Email | NOTIFY-002 | P2 |
| 10-5 | Retry/backoff + dead-letter + escalation + scheduled reminder | NOTIFY-001 | P2 |
| 10-6 | ยืนยันด้วย test ว่า provider ล่มไม่ทำให้ transaction หลัก rollback (ACCEPTANCE-CRITERIA) | NOTIFY-005 | P2 |

---

### Phase LEGACY-11 — Evaluation & Reporting `[P2]`

| # | งาน | Gap |
|---|---|---|
| 11-1 | ขยาย `PromotionScreeningController` ให้รวมข้อมูลเวลา/สาย/OT ไม่ใช่เฉพาะข้อมูลลา | EVAL-001 |
| 11-2 | ทำเกณฑ์การประเมินเป็น policy ที่ configurable + versioned (เลิก hard-code ในรายงาน) | EVAL-001 |
| 11-3 | Evaluation period metrics + export PDF/Excel/CSV (ต่อยอดจาก export suite เดิมที่ PASS อยู่แล้ว) | EVAL-001, REPORT-001 |

---

### Phase LEGACY-12 — Future Modules Readiness `[P3]`

| # | งาน | Gap |
|---|---|---|
| 12-1 | ยกระดับ `config/modules.php` + `menus` เป็น Module Registry จริงตาม `docs/08` (code, name, route, icon, version, enabled, dependencies, permissions, order) | CORE-MOD-001 |
| 12-2 | ยืนยันว่า Core รองรับโมดูลอนาคต: สารบรรณ (มีแล้ว), แผน/งบประมาณ, จองห้อง, จองรถ, พัสดุ, การเงิน, ครุภัณฑ์, ซ่อมบำรุง, ประชุม | REQ-CORE-001 |
| 12-3 | เพิ่ม frontend lint/test/TypeScript ทีละไฟล์ | TEST-004 |
| 12-4 | Accessibility pass + responsive verification | UI-002 |

---

## 4. Database Migration Pattern

ห้าม:

```text
DROP old_column
RENAME old_column
Deploy
```

ให้ใช้:

```text
1. ADD new schema           ← nullable, ไม่มีโค้ดอ่าน
2. Deploy compatible code   ← เขียนทั้งเก่าและใหม่ (dual-write)
3. Backfill data            ← เป็น batch, monitor lock
4. Validate                 ← row count + checksum + spot check
5. Switch reads             ← feature flag
6. Switch writes            ← หยุดเขียนของเก่า
7. Observe                  ← อย่างน้อย 1 รอบธุรกิจเต็ม (สำหรับ Leave = 1 ปีงบประมาณ หรือช่วงที่ตกลงกัน)
8. Cleanup later            ← ต้องขออนุมัติแยกต่างหาก
```

### 4.1 ข้อควรระวังเฉพาะระบบนี้

- **MySQL 8 DDL lock**: ระบบนี้เคยเจอ DDL timeout จริง (`2026_08_08_100050_resume_interrupted_leave_hardening.php`) → ทุก `ALTER TABLE` บนตารางใหญ่ต้องประเมิน lock, ทำนอกเวลาราชการ, และมี resume path
- **ห้ามรัน `down()` บน production** — migration หลายตัว drop table ทั้งตาราง
- **ทดสอบ migration บน MySQL 8 เสมอ** ไม่ใช่ SQLite (DB-RISK-008)
- **`enum` ของ MySQL** — เพิ่มค่าใหม่ต้อง `ALTER` เสมอ; กรณี `'Voided'` ต้องตรวจก่อน (DB-RISK-007)

---

## 5. Migration Specification Template

ทุก schema change ต้องระบุ:

```text
Migration ID:
Reason:                    (อ้าง Gap ID จาก LEGACY-GAP-ANALYSIS)
Old Schema:
New Schema:
Data Mapping:
Backfill:                  (batch size, ระยะเวลาโดยประมาณ)
Compatibility Strategy:    (dual-write / dual-read / feature flag)
Validation Query:          (SQL ที่พิสูจน์ว่าข้อมูลถูกต้อง)
Rollback:                  (สคริปต์จริง ไม่ใช่คำอธิบาย)
Risk:
Downtime:                  (คาดการณ์ + lock analysis บน MySQL 8)
Tested on MySQL 8:         ☐ yes  ☐ no    ← ต้องเป็น yes ก่อน deploy
```

---

## 6. Cutover Strategy

| Phase | กลยุทธ์ที่เลือก | เหตุผล |
|---|---|---|
| LEGACY-02 (Security) | **Staged rollout ทีละกลุ่มช่องโหว่** + revert ได้ทันที | ต้องแก้เร็ว แต่การย้ายไฟล์อาจกระทบการแสดงผล |
| LEGACY-03 (Data) | **Additive + dual-write** | ไม่มีการเปลี่ยนพฤติกรรมจนกว่าจะ switch reads |
| LEGACY-04 (RBAC) | **Feature flag** `USE_NEW_PERMISSION_ENGINE` + snapshot สิทธิ์ก่อน/หลัง | สิทธิ์ผิดพลาดกระทบทันทีทั้งระบบ |
| LEGACY-05 (Workflow) | **Shadow read** ก่อน แล้วจึง switch | ประวัติการอนุมัติผิดพลาดแก้ย้อนหลังไม่ได้ |
| LEGACY-08 (Attendance) | **Department pilot** — เริ่มจากฝ่ายเดียว | connector vendor มีความไม่แน่นอนสูง |
| LEGACY-10 (Notification) | **Dual send + delivery log** ช่วงแรก | ต้องพิสูจน์ว่าส่งถึงจริงก่อนตัดของเดิม |

**หลีกเลี่ยง Big Bang Cutover ทุกกรณี**

---

## 7. Verification Checklist

หลังทุก Phase:

- [ ] `./vendor/bin/pint --test` (lint)
- [ ] `php artisan test` เขียว 100%
- [ ] Authorization test matrix ผ่าน
- [ ] IDOR test ผ่านทุก resource
- [ ] Migration test บน **MySQL 8** ผ่าน
- [ ] `npm run build` ผ่าน
- [ ] Row count เทียบกับ baseline — อธิบายส่วนต่างได้ทุกรายการ
- [ ] Checksum ตารางวิกฤต (`leaves`, `leave_approvals`, `leave_balances`, `attendances`, `saraban_documents`)
- [ ] `audit_logs` มีบันทึกครบสำหรับการเปลี่ยนแปลงที่เกิดใน phase นั้น
- [ ] Backup ล่าสุดสำเร็จและ restore drill ผ่าน
- [ ] `docs/TRACEABILITY-MATRIX.md`, `TASKS.md`, `docs/19-CHANGELOG.md`, `docs/20-DECISION-LOG.md` อัปเดตแล้ว

---

## 8. Rollback Criteria

Rollback ทันทีเมื่อ:

- Data loss ใด ๆ ก็ตาม
- Auth bypass
- IDOR regression
- **Approval history mismatch แม้แต่รายการเดียว**
- **Leave balance mismatch** — `used`/`reserved` ไม่ตรงกับผลรวมของ `leaves` ที่เกี่ยวข้อง
- Attendance data mismatch เกิน threshold ที่ตกลงกันไว้
- ผู้ใช้ได้รับสิทธิ์ **เพิ่มขึ้น** โดยไม่ตั้งใจหลัง LEGACY-04
- เอกสาร/PDF ที่เคยเปิดได้ เปิดไม่ได้หลังย้ายไฟล์
- Critical production failure

---

## 9. Legacy Compatibility Register

| Legacy Feature | Temporary Compatibility | Removal Condition |
|---|---|---|
| `users.role_id` + ตาราง `roles` legacy | Compat adapter map → Spatie roles | RBAC/ABAC verified ครบ + ไม่มีโค้ดอ่าน `role_id` + observe 1 รอบธุรกิจ |
| `users.custom_permissions` (json) | อ่านควบคู่กับ Spatie `model_has_permissions` | สิทธิ์ทั้งหมด migrate เข้า Spatie และ verify snapshot ตรงกัน |
| Permission ชื่อเก่า (`view_users`, `attendance.view_all`, …) | ตาราง `permission_aliases` | ทุก client/route ใช้ชื่อ canonical แล้ว |
| `approval_steps` + `leaves.approval_level` | อ่านอย่างเดียว; workflow engine เดิน shadow คู่ขนาน | Workflow engine ตรงกัน 100% ตลอดช่วง observe |
| `leave_action_logs` (ซ้ำกับ `leave_audit_logs`) | คงไว้อ่านอย่างเดียว | migrate เข้า `audit_logs` กลางและ verify ครบ |
| `attendances` แบบ 1 แถว/วัน + `unique(user_id,date)` | Engine ใหม่เขียนผลลัพธ์ในรูปแบบเดิม | Raw event pipeline verified + reconciliation ผ่าน |
| คอลัมน์ OT ใน `attendances` | อ่านอย่างเดียวหลัง backfill เข้า `ot_time_records` | รายงาน OT ใหม่ตรงกับของเดิมทุกรายการ |
| `departments` (แบน) | Map ไป `org_units` + dual-read | ทุก query ใช้ `org_unit_id` และ observe ผ่าน |
| Auto-increment id ใน URL | รับทั้ง id และ uuid ที่ route binding | client ทั้งหมดใช้ uuid แล้ว |
| `/api/storage/{path}` (สาธารณะ) | **ไม่มี compatibility — ลบทันทีใน LEGACY-02** | เป็นช่องโหว่ ไม่ใช่ feature |
| ไฟล์เดิมใน `storage/app/public` | คงไฟล์ต้นฉบับไว้หลัง copy ไป private | verify checksum + เปิดเอกสารเดิมได้ครบ แล้วจึงลบ |
| Vendor attendance feed | Connector (ยังไม่มี) | New integration verified + reconciliation ผ่าน |

---

## 10. Final Exit Criteria

Migration ถือว่าเสร็จเมื่อ:

- [ ] P0 gaps = 0 (14 รายการ)
- [ ] P1 gaps = 0 (16 รายการ)
- [ ] CORE CHECKPOINT PASS
- [ ] Data reconciliation PASS — ไม่มีข้อมูลสูญหายแม้แต่แถวเดียว
- [ ] Rollback tested บน staging ทุก phase ที่มี schema change
- [ ] Production build PASS
- [ ] Backup + restore drill สำเร็จตามรอบที่กำหนด
- [ ] `docs/TRACEABILITY-MATRIX.md` เชื่อม Requirement → Implementation → Test ครบทุก REQ
- [ ] ไม่มี `hasRole()` ที่ hard-code ชื่อเหลืออยู่
- [ ] ไม่มีไฟล์ส่วนบุคคลที่เข้าถึงได้โดยไม่ยืนยันตัวตน
- [ ] `docs/19-CHANGELOG.md` และ `docs/20-DECISION-LOG.md` อัปเดตครบ

---

## 11. สรุปลำดับและการพึ่งพา

```text
LEGACY-00 Freeze & Baseline        [P0] ─┬─► บล็อกทุกอย่าง
                                          │
LEGACY-01 Stabilization            [P0/P1]┤   (+ ย้าย 10-1, 10-2 มาทำที่นี่)
                                          │
LEGACY-02 Security Hardening       [P0] ──┤   ◄── ห้ามข้าม ห้ามเลื่อน
                                          │
LEGACY-03 Core Data Foundation     [P1] ──┼──► ต้องมาก่อน 04 และ 06
                                          │
LEGACY-04 RBAC + ABAC              [P1] ──┼──► ต้องมาก่อน 05
                                          │
LEGACY-05 Workflow Engine          [P1] ──┘
                                          │
                              ═══ CORE CHECKPOINT ═══
                                          │
LEGACY-06 Time Foundation          [P2] ──┬──► ต้องมาก่อน 08, 09
LEGACY-07 Leave Modernization      [P2] ──┤
LEGACY-08 Attendance Integration   [P2] ──┤   ⚠ บล็อกด้วย Open Question #1, #2
LEGACY-09 Overtime                 [P2] ──┤
LEGACY-10 Notification Center      [P2] ──┤
LEGACY-11 Evaluation & Reporting   [P2] ──┤
LEGACY-12 Future Modules Readiness [P3] ──┘
```

---

## 12. คำถามที่ต้องได้คำตอบก่อนเริ่ม (ยกมาจาก Assessment §18)

| # | คำถาม | บล็อก Phase |
|---|---|---|
| 1 | เครื่องสแกนใบหน้า/ลายนิ้วมือยี่ห้อ/รุ่นอะไร และระบบผู้ผลิตส่งข้อมูลออกมาทางไหน? | LEGACY-08 |
| 2 | ระบบผู้ผลิตใช้อะไรระบุตัวบุคลากร และ map กับ `users` ด้วยฟิลด์ไหน? | LEGACY-08 |
| 3 | ระบบใช้งานจริงบน production แล้วหรือยัง มีข้อมูลจริงกี่ราย? | LEGACY-00 |
| 4 | `docs/02-TECH-STACK.md` ระบุ Next.js + PostgreSQL แต่ระบบจริงเป็น Laravel + MySQL — **ต้องการเปลี่ยน stack จริงหรือไม่?** (ถ้าใช่ = rewrite ซึ่งขัดกฎสูงสุดข้อ 1-2) | **ทุก Phase** |
| 5 | โมดูลสารบรรณเปิดใช้จริงแล้วหรือยัง และมีเอกสารลับในระบบหรือไม่? | LEGACY-02 |
| 6 | running number ใช้ปีงบประมาณหรือปีปฏิทิน พ.ศ.? (Saraban ใช้ปีงบประมาณ, Leave ใช้ตาม policy `period_basis` — อาจไม่ตรงกัน) | LEGACY-07 |
| 7 | ต้องการให้ token หมดอายุกี่นาที และยอมให้ผู้ใช้ต้อง login ใหม่บ่อยขึ้นได้หรือไม่? | LEGACY-02 |

---

## Sign-off

| บทบาท | ชื่อ | วันที่ | ผล |
|---|---|---|---|
| ผู้จัดทำ | AI Agent (Claude Code) | 2026-08-12 | เสร็จรอบที่ 1 |
| ผู้ตรวจทาน | | | ☐ อนุมัติ ☐ ขอแก้ไข |
| ผู้อนุมัติให้เริ่ม LEGACY-00 | | | ☐ อนุมัติ ☐ ไม่อนุมัติ |

> **แผนนี้ยังไม่ได้รับอนุมัติ — ห้ามแก้ Source Code, ห้ามสร้าง Migration, ห้ามเริ่ม CORE-01, ห้ามเริ่ม e-Leave/Attendance/OT**
