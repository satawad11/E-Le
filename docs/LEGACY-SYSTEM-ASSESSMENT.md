# LEGACY SYSTEM ASSESSMENT

> เอกสารนี้ใช้สำหรับตรวจระบบเดิมก่อนเริ่มแก้ไขหรือ Refactor
>
> **สถานะ:** กรอกข้อมูลจริงแล้ว — รอบที่ 1
> **วันที่ตรวจ:** 12 สิงหาคม 2569 (2026-08-12)
> **ผู้ตรวจ:** AI Agent (Claude Code) ตาม `LEGACY-UPGRADE-PROMPT.md`
> **ขอบเขตที่ตรวจ:** `D:\E-Le` (working tree ปัจจุบัน) — backend Laravel + frontend Vue 3 + docker
> **Baseline commit:** `20abc58` (2026-07-18) — หมายเหตุ: working tree มีการแก้ไขที่ยังไม่ commit จำนวนมาก (ดู §14.1)
> **ยังไม่มีการแก้ไข Source Code ใด ๆ** — เอกสารนี้เป็นผลการอ่านอย่างเดียว

---

## 1. วัตถุประสงค์

- ทำความเข้าใจระบบเดิมจาก Source Code และ Database จริง
- ระบุสิ่งที่ใช้งานได้และควรเก็บไว้
- ระบุ Technical Debt, Security Risk และข้อจำกัดในการขยายระบบ
- ป้องกันการรื้อระบบโดยไม่จำเป็น
- เตรียมข้อมูลสำหรับ Gap Analysis และ Migration Plan

## 2. กฎสำคัญ

1. ห้ามแก้ Source Code ในรอบ Assessment
2. ห้าม Drop/Rename Table หรือ Column
3. ห้ามลบข้อมูลจริง
4. ห้ามสร้าง Architecture ใหม่ก่อนเข้าใจระบบเดิม
5. ทุกข้อสรุปต้องอ้างอิงไฟล์, โมดูล, Route, API, Migration หรือ Table ที่ตรวจพบจริง
6. ถ้าไม่แน่ใจ ให้ระบุ `UNKNOWN` แทนการเดา

### 2.1 ข้อจำกัดของการตรวจรอบนี้ (ต้องอ่านก่อน)

| ข้อจำกัด | ผลกระทบต่อความเชื่อถือได้ |
|---|---|
| ไม่มี `php` และ `node` ใน PATH ของสภาพแวดล้อมที่ตรวจ | **ไม่ได้รัน** `php artisan test`, `php artisan migrate:status`, `php artisan route:list`, `npm run build` จริง — ผลใน §13 อ้างจาก `.phpunit.result.cache` เท่านั้น |
| ไม่ได้เชื่อมต่อฐานข้อมูล MySQL จริง | โครงสร้างตารางอ่านจาก migration files ไม่ใช่จาก `information_schema` — อาจมี drift ระหว่าง migration กับ DB จริง (ดู DB-RISK-006) |
| ไม่มี production environment ให้ตรวจ | ค่า `SESSION_SECURE_COOKIE`, TLS, backup จริง = `UNKNOWN` |

> **สิ่งที่ต้องทำก่อนอนุมัติเอกสารนี้:** รัน `php artisan migrate:status`, `php artisan test`, และ `SHOW CREATE TABLE` บน DB production เพื่อยืนยัน §6 และ §13

---

## 3. ข้อมูลพื้นฐานระบบ

| รายการ | ผลการตรวจ | หลักฐาน |
|---|---|---|
| Framework | Laravel `^13.8` (backend), Vue `^3.5.34` SPA แยก repo folder (frontend) | `backend/composer.json:12`, `frontend/package.json` |
| Runtime | PHP `^8.3` (platform pinned `8.4.23`), Node 22 (Docker) | `backend/composer.json` (`require.php`, `config.platform.php`), `frontend/Dockerfile` |
| Database | MySQL 8.0 (`DB_CONNECTION=mysql`, `DB_DATABASE=eleave`) — แต่ **test suite ใช้ SQLite in-memory** | `backend/.env:21-27`, `docker-compose.yml` (`image: mysql:8.0`), `backend/phpunit.xml` (`DB_CONNECTION=sqlite`) |
| Authentication | Laravel Sanctum personal access token (Bearer) — login ด้วย email **หรือ** เลขบัตรประชาชน 13 หลัก (ผ่าน `id_card_number_hash`) | `backend/app/Http/Controllers/AuthController.php:11-62`, `backend/config/sanctum.php` |
| Authorization | Spatie laravel-permission `^8.3` + **role name hard-code** ปนอยู่ 31 จุด + `Gate::before` ให้ Super Admin ผ่านทุกอย่าง | `backend/app/Providers/AppServiceProvider.php:25-30`, grep `hasRole|hasAnyRole` = 31 hits ใน 12 ไฟล์ |
| File Storage | Local disk 2 ตัว: `public` (`storage/app/public`, visibility=public) และ `local` (`storage/app/private`) — ใช้ปนกัน | `backend/config/filesystems.php:33-45` |
| Queue/Worker | `QUEUE_CONNECTION=database` และ Notification เป็น `ShouldQueue` แต่ **ไม่มี worker container / ไม่มี `queue:work` ที่ไหนเลย** | `backend/.env:42`, `backend/app/Notifications/LeaveRequested.php:11`, `docker-compose.yml` (ไม่มี service worker) |
| Notification | In-App (`notifications` table), LINE Messaging API, Telegram — เรียกแบบ synchronous ใน request | `backend/app/Services/LineNotificationService.php`, `TelegramNotificationService.php`, `TelegramService.php` |
| Deployment | Docker Compose 2 service (db, backend, frontend) — backend รันด้วย `php artisan serve` (dev server) | `docker-compose.yml:20-56` |
| Docker | มี `docker/nginx/default.conf` + root `Dockerfile` (php-fpm) แต่ **ไม่ได้ถูกใช้ใน `docker-compose.yml`** — มี 2 stack ที่ไม่ตรงกัน | `docker-compose.yml` vs `Dockerfile` + `docker/nginx/default.conf` |
| Testing | PHPUnit 12, 18 ไฟล์ทดสอบ / ~86 test methods, ไม่มี frontend test/lint/typecheck เลย | `backend/tests/**`, `frontend/package.json` (scripts มีแค่ dev/build/preview) |
| Modules ที่มีจริง | Leave (ครบที่สุด), Attendance+OT, Saraban (สารบรรณ), Reports, Menu/RBAC admin, Notification | `backend/app/Http/Controllers/**` |

### 3.1 ขนาดระบบ

| หน่วยนับ | จำนวน |
|---|---:|
| Migration files | 91 |
| Eloquent Models | 40 |
| Controllers | 45 |
| Services | 20 |
| API endpoints (นับ `Route::` verb) | 168 (`api.php` 135, `leave_government.php` 31, `web.php` 2) |
| Vue views | 43 |
| Vue components | 6 |
| Seeders | 13 |

---

## 4. Existing Architecture

```text
Browser (Vue 3 SPA, Pinia, Vue Router 5, Tailwind 4)
   │  token = localStorage['token']  (Bearer, ไม่มีวันหมดอายุ)
   │  axios → VITE base URL
   ▼
Laravel 13 REST API  (routes/api.php + routes/leave_government.php)
   │  middleware: auth:sanctum, throttle, SanitizeInput, spatie permission/can
   ▼
Controllers (fat — LeaveController 888 บรรทัด, AttendanceController 1137 บรรทัด)
   │
   ├── Services (LeaveService, LeavePolicyService, LeaveBalanceService,
   │             Saraban\DocumentWorkflowService, Line/Telegram, ThaiDateService)
   │
   ├── Eloquent Models → MySQL 8 (single schema, ไม่มี domain boundary)
   │
   ├── Storage: storage/app/public  (สาธารณะ) และ storage/app/private (ปลอดภัย)
   │
   └── External: LINE Messaging API (push), Telegram Bot API  ← เรียกตรงใน request

Scheduler (routes/console.php)  ← ประกาศไว้ แต่ไม่มี container รัน schedule:work
Queue (database driver)         ← ประกาศไว้ แต่ไม่มี worker
```

**Entry points**
- `backend/public/index.php` (Laravel)
- `backend/routes/api.php` — prefix `/api`
- `backend/routes/leave_government.php` — โหลดเพิ่มใน `bootstrap/app.php:12` ด้วย prefix `/api` เช่นกัน (ลงทะเบียนทีหลัง จึง **ทับ** route ชื่อซ้ำใน `api.php`)
- `backend/routes/web.php` — มี `/fix-leaves` ที่เขียน DB โดยไม่มี auth (ดู SEC-002)
- `frontend/src/main.js` → `App.vue` → `router/index.js`

**Module boundaries**
- แยกด้วย namespace เฉพาะ Saraban เท่านั้น (`App\Models\Saraban\*`, `App\Http\Controllers\Api\Saraban\*`, ตาราง prefix `saraban_`)
- Leave / Attendance / OT / Report **ไม่มี boundary** — อยู่ปนกันใน `App\Http\Controllers\` root และตารางไม่มี prefix
- ไม่มี Module Registry ตาม `docs/08-MODULE-STANDARDS.md` — มี `backend/config/modules.php` และตาราง `menus` แต่ใช้เป็น sidebar config ไม่ใช่ registry จริง

**Shared services**
`SystemSettingService` (settings จาก DB override config runtime), `ThaiDateService` (แปลง พ.ศ.), `MenuService`, Spatie permission

**External integrations**
LINE Messaging API, Telegram Bot API เท่านั้น — **ไม่มี integration กับเครื่องสแกนใบหน้า/ลายนิ้วมือ** (ดู §11)

**Data ownership**
ทุกโมดูลอ่าน/เขียน `users`, `departments` ตรง ๆ ไม่มี service contract — Saraban `DocumentController::store()` query `User::whereIn('department_id', ...)` โดยตรง (`app/Http/Controllers/Api/Saraban/DocumentController.php:337`)

---

## 5. Existing Modules

| Module | สถานะ | ใช้งานจริง | คุณภาพ | ควรเก็บ/Refactor/Replace | หมายเหตุ |
|---|---|---:|---|---|---|
| Organization | มีบางส่วน | ใช้ | ต่ำ | **REFACTOR** | `departments` แบน ไม่มี `parent_id`, ไม่มี effective date, มีแค่ `head_of_department_id` + `is_active` (`0000_00_00_000001`, `2026_06_20_082600`) |
| Personnel | มี | ใช้ | ปานกลาง | **KEEP + REFACTOR** | `personnel_types` (code/name/is_active) + `users.personnel_type_id` มีจริง แต่ไม่มี assignment history / effective dating (`2026_08_08_100000`) |
| Authentication | มี | ใช้ | ต่ำ-ปานกลาง | **REFACTOR** | bcrypt 12 rounds ok, throttle 10/min ok — แต่ token ไม่หมดอายุ, ไม่มี lockout, ไม่มี password reset, ไม่มี 2FA, ไม่มี session/device management |
| RBAC/Permission | มี | ใช้ | ต่ำ | **REFACTOR (หนัก)** | Spatie ติดตั้งครบ + UI จัดการสิทธิ์รายคน แต่มี permission 3 generation ปนกัน และ role name hard-code 31 จุด; **ไม่มี ABAC/scope engine** |
| Workflow | มีแบบ hard-code | ใช้ | ต่ำ | **REPLACE** | `approval_steps` = (role_id, step_order, name) เท่านั้น; `leaves.approval_level` เป็น integer เลื่อนทีละขั้น; ไม่มี versioning/parallel/conditional/escalation |
| Leave | มี ครบที่สุด | ใช้หนัก | **ดี** (ชั้น government) / ต่ำ (ชั้นเก่า) | **KEEP ชั้นใหม่ + REFACTOR ชั้นเก่า** | `leave_policies` มี effective_from/to + pin `leaves.leave_policy_id` แล้ว — เป็นรากฐานที่ดีมาก; แต่ `LeaveController` เก่ายังมี IDOR |
| Attendance | มี | ใช้ | ต่ำ | **REFACTOR** | GPS + selfie ผ่านเว็บเท่านั้น, 1 แถว/คน/วัน (`unique(user_id,date)`), ไม่มี raw event store, ไม่มี device |
| OT | มีบางส่วน | ใช้ | ต่ำ | **REFACTOR** | เป็นแค่ 5 คอลัมน์ใน `attendances` (`ot_check_in_time`, `ot_check_out_time`, `ot_minutes`, …) ไม่มี OT order/assignment/certified time (`2026_06_21_000002`) |
| Notification | มี | ใช้ | ต่ำ | **REFACTOR** | In-App + LINE + Telegram; ไม่มี template/preference/retry/dead-letter/delivery log; ไม่มี worker รันคิว |
| Reports | มี | ใช้ | ปานกลาง | **KEEP + REFACTOR** | PDF (dompdf), Word, CSV, Excel; รายงานราชการ (ใบลา, บัตรลา, เลื่อนเงินเดือน) ทำได้ค่อนข้างครบ |
| Saraban (สารบรรณ) | มี | UNKNOWN | ต่ำ | **REFACTOR (หนัก)** | schema ดี (13 ตาราง, sequence atomic, signature, circular) แต่ **แทบไม่มี authorization เลย** ดู SEC-005/006/007 |

---

## 6. Database Assessment

### 6.1 กลุ่มตารางหลัก (จาก migration 91 ไฟล์)

| กลุ่ม | ตาราง |
|---|---|
| Core | `users`, `departments`, `roles` (legacy), `personnel_types`, `user_profiles`, `settings`, `fiscal_years`, `holidays`, `menus`, `document_templates`, `user_delegations` |
| Spatie | `permissions`, `roles`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` |
| Leave | `leaves`, `leave_types`, `leave_approvals`, `leave_balances`, `leave_policies`, `leave_evidences`, `leave_audit_logs`, `leave_action_logs`, `leave_adjustments`, `leave_document_sequences`, `approval_steps` |
| Attendance | `attendances`, `work_calendar_exceptions` |
| Saraban | `saraban_documents`, `saraban_document_types`, `saraban_document_sequences`, `saraban_document_incoming`, `saraban_document_outgoing`, `saraban_document_attachments`, `saraban_document_receivers`, `saraban_document_user_states`, `saraban_document_workflow_templates`, `saraban_document_workflow_steps`, `saraban_document_signatures`, `saraban_document_circulars`, `saraban_document_circular_recipients`, `saraban_audit_logs` |
| Platform | `sessions`, `cache`, `jobs`, `notifications`, `personal_access_tokens`, `personal_data_access_logs`, `password_reset_tokens` |

### 6.2 สิ่งที่ทำได้ดีอยู่แล้ว (ต้องรักษาไว้)

- **Foreign key ครบ** และใช้ `nullOnDelete()` / `cascadeOnDelete()` อย่างตั้งใจในตารางใหม่
- **Effective dating** มีจริงใน `leave_policies` (`effective_from`, `effective_to`, `is_active`) และ `leaves` pin `leave_policy_id` ไว้ → ตรงตาม ADR-002 บางส่วน (`2026_08_08_100000_harden_leave_management_for_thai_government.php:30-55`)
- **Soft delete** ใน `leaves` และ `saraban_documents` — void ใบลาไม่ลบประวัติ (`LeaveGovernanceController::void`)
- **Sequence แบบ atomic** ทั้ง `leave_document_sequences` และ `saraban_document_sequences` ใช้ `DB::transaction` + `lockForUpdate()` + unique constraint
- **Evidence integrity**: `leave_evidences.sha256` + `leaves.content_hash` (ผนึกตอนอนุมัติ) → ตรวจการแก้ไขย้อนหลังได้
- **Index เพิ่มเติม** มี migration เฉพาะ (`2026_06_14_000000_add_indexes_to_leaves_table`, `2026_07_11_000000_add_performance_indexes`)

### 6.3 Database Risk Register

| Risk ID | Table/Field | ปัญหา | Severity | ผลกระทบ | แนวทาง |
|---|---|---|---|---|---|
| DB-RISK-001 | `departments` | ไม่มี `parent_id` / ไม่มีลำดับชั้น / ไม่มี effective dating — org chart แบนราบ | HIGH | ทำ ABAC scope `UNIT`/`DESCENDANTS` ไม่ได้เลย; approval chain ตามสายบังคับบัญชาทำไม่ได้ | เพิ่มตาราง `org_units` แบบ additive + adapter อ่านจาก `departments` (LEGACY-03) |
| DB-RISK-002 | `users.role_id` (legacy) + Spatie `model_has_roles` | มีระบบ role **สองชุดพร้อมกัน** — โค้ดอ่านสลับกันไปมา; `ApprovalController::pending` รวม `$user->role_id` เข้ากับ Spatie role ids โดยตรงทั้งที่เป็น id คนละ namespace | **CRITICAL** | สิทธิ์อนุมัติผิดตัว / เห็นใบลาผิดคน; `LeaveController::update/destroy` เรียก `$user->role->name` จะ crash ถ้า `role_id` เป็น null | ทำ compat adapter แล้วเลิกใช้ `users.role_id` (LEGACY-04) |
| DB-RISK-003 | `approval_steps` | workflow เป็น global table เดียว (role_id, step_order) ไม่ผูกกับ department/personnel type/leave type และ **ไม่มี version** | HIGH | แก้ workflow แล้วกระทบใบลาที่กำลังเดินอยู่และประวัติย้อนหลัง | สร้าง workflow engine ใหม่ + pin version ที่ instance (LEGACY-05) |
| DB-RISK-004 | `document_templates` | ไม่มี `version` / `effective_from` / `effective_to` และ `leaves` ไม่ pin template | HIGH | แก้เทมเพลตแล้วใบลาที่อนุมัติไปแล้วพิมพ์ออกมาเปลี่ยนรูป — ผิด `docs/14` และ ACCEPTANCE-CRITERIA | เพิ่มคอลัมน์ version/effective + `leaves.document_template_version_id` แบบ additive |
| DB-RISK-005 | `attendances` | `unique(user_id, date)` 1 แถว/คน/วัน + ไม่มีตาราง raw event | HIGH | รับข้อมูลจากเครื่องสแกน (หลาย event/วัน) ไม่ได้; dedup/reconcile ทำไม่ได้ | เพิ่ม `attendance_raw_events` แยก แล้วให้ `attendances` เป็นผลลัพธ์ที่คำนวณ (LEGACY-08) |
| DB-RISK-006 | migration ทั้งชุด | `2026_08_08_100000` ขึ้นต้นด้วย `if (Schema::hasTable('personnel_types')) return;` และมี migration ตามหลังชื่อ `..._resume_interrupted_leave_hardening` — ยอมรับตรง ๆ ว่าเคยมี DDL timeout กลางคัน | **CRITICAL** | schema จริงใน production **อาจไม่ตรงกับ migration** — ต้องตรวจก่อนทำอะไรทั้งสิ้น | รัน `migrate:status` + diff `information_schema` กับ schema ที่ migration ควรสร้าง (LEGACY-00) |
| DB-RISK-007 | `leaves.status` / `leave_approvals.status` | ใช้ `enum(...)` ของ MySQL แต่โค้ดเพิ่มค่า `'Voided'` เข้าไป (`LeaveGovernanceController::void`) | HIGH | ถ้า enum ยังไม่ถูกขยาย MySQL จะปัด `Voided` เป็นค่าว่างหรือ error → **ข้อมูลสูญ** | ยืนยันด้วย `SHOW CREATE TABLE leaves` ทันที; แปลง enum → string + CHECK |
| DB-RISK-008 | test vs prod | test รัน SQLite in-memory, prod เป็น MySQL 8 | HIGH | migration ที่พังเฉพาะ MySQL (enum, DDL lock, index length) จะผ่าน CI แล้วไปพังจริง | เพิ่ม CI job รัน migration บน MySQL 8 |
| DB-RISK-009 | `users.custom_permissions` (json) | มี direct permission ทั้งใน Spatie `model_has_permissions` และคอลัมน์ json นี้ | MEDIUM | สิทธิ์สองแหล่ง — ไม่รู้ว่าอันไหนชนะ | รวมเข้า Spatie ให้เหลือแหล่งเดียว |
| DB-RISK-010 | audit tables 4 ชุด | `leave_audit_logs`, `leave_action_logs`, `saraban_audit_logs`, `personal_data_access_logs` แยกกัน และ **ไม่มี audit ของ users/roles/permissions/attendance/settings** | HIGH | ตรวจสอบย้อนหลังไม่ครบ; ผิด `docs/12-AUDIT-LOGGING.md` | สร้าง `audit_logs` กลาง แล้ว dual-write ก่อน cutover |
| DB-RISK-011 | `backend/eleave` | ไฟล์ SQLite database ถูก **commit เข้า git** (`bc53196`) | MEDIUM | ข้อมูล/artifact หลุดเข้า repo; สับสนกับ DB จริง | ตรวจเนื้อหา แล้วลบออกจาก repo + เพิ่มใน `.gitignore` |
| DB-RISK-012 | ทุกตาราง | ใช้ auto-increment `id` เป็น public identifier ใน URL ทั้งหมด (`/api/leaves/{leave}`) ไม่มี UUID | MEDIUM | enumeration ง่าย; ผิด `PROJECT_RULES` ข้อ 4 | เพิ่มคอลัมน์ `uuid` แบบ additive + backfill + สลับ route binding (LEGACY-03) |

---

## 7. Authentication Assessment

| หัวข้อ | ผลตรวจ | หลักฐาน |
|---|---|---|
| Password hashing | bcrypt, `BCRYPT_ROUNDS=12` — **ผ่าน** | `backend/.env.example`, `User::casts()` → `'password'=>'hashed'` |
| Login identifier | email **หรือ** เลขบัตรประชาชน 13 หลัก (ค้นด้วย `sha256(idcard + APP_KEY)`) | `AuthController.php:25-35` |
| Token lifecycle | Sanctum PAT, **`'expiration' => null` = ไม่มีวันหมดอายุ** | `backend/config/sanctum.php:46` |
| Token storage (client) | `localStorage['token']` และถูกส่งใน **query string** สำหรับ export | `frontend/src/stores/auth.js:18,160`, `frontend/src/views/admin/AttendanceSummaryView.vue:267-281` |
| Session lifecycle | `SESSION_DRIVER=file` ใน `.env` (config default = database), lifetime 120 นาที | `backend/.env:34-38` |
| Cookie flags | `http_only=true` (default ok), `same_site=lax`, **`secure` = `env('SESSION_SECURE_COOKIE')` ซึ่งไม่ได้ตั้งใน `.env`** → ไม่มี Secure flag | `backend/config/session.php:172,185,202`, `backend/.env` |
| Account lockout | **ไม่มี** — มีแค่ `throttle:10,1` ต่อ IP บน `/api/login` | `routes/api.php:33` |
| Session revocation | logout ลบเฉพาะ token ปัจจุบัน; ไม่มี "logout ทุกอุปกรณ์"; **ปิดบัญชี (`is_active=false`) ไม่เพิกถอน token ที่ออกไปแล้ว** | `AuthController::logout`, `Admin\UserController::toggleStatus` (ไม่มี `tokens()->delete()`) |
| Device/session management | **ไม่มี** | — |
| 2FA | **ไม่มี** | — |
| Password reset | **ไม่มี endpoint** — ตาราง `password_reset_tokens` มีแต่ไม่มี controller/route | grep ไม่พบ route reset password |
| Disabled account | ตรวจตอน login เท่านั้น (`AuthController.php:44-48`) — token เดิมยังใช้ได้ต่อ | ดูข้างบน |

---

## 8. Authorization Assessment

| หัวข้อ | ผลตรวจ |
|---|---|
| Role hard-code | **ใช่ — รุนแรง** 31 จุดเรียก `hasRole()/hasAnyRole()` ด้วยชื่อไทยตรง ๆ ใน 12 ไฟล์ (`ผู้ดูแลระบบสูงสุด` ปรากฏ 18 ครั้งใน backend) และซ้ำอีกชุดใน `frontend/src/stores/auth.js:26-56` — เปลี่ยนชื่อ role ในหน้าจอ = สิทธิ์พังทันที |
| Permission model | Spatie + **3 generation ปนกัน**: (ก) ไม่มีจุด เช่น `view_users`, `edit_users`, `manage_settings` (ข) 2 ระดับ เช่น `attendance.view_all`, `leave.my` (ค) 3 ระดับตามมาตรฐาน เช่น `attendance.record.view_my`, `saraban.document.view` |
| Alias layer | `frontend/src/stores/auth.js:63-76` map ชื่อ permission ข้ามรุ่นกันเอง — เป็นหลักฐานว่า naming แตกเป็นเสี่ยง |
| Server-side authorization | **ไม่ครบ** — endpoint สำคัญหลายตัวมีแค่ `auth:sanctum` ไม่มี permission/ownership (ดู §9) |
| Organization scope | มีแบบ hard-code เฉพาะ "หัวหน้าแผนก" ใน `LeaveController::index:44-50` และ `ApprovalController::pending:73-82`; **ไม่มี scope engine** ตาม `docs/06` |
| Own-resource scope | มีบางที่ (`LeaveEvidenceController::authorizeAccess`, `acceptSubstitute`) แต่ **ไม่มีที่อื่นอีกหลายจุด** |
| Direct user permissions | มี (Spatie `getDirectPermissions()` + UI `Admin\PermissionController`) — **ผ่าน** |
| Allow/Deny precedence | **ไม่มี explicit deny** — Spatie ไม่รองรับ; `Gate::before` ให้ Super Admin ผ่านทุกอย่างแบบไม่มีทางปฏิเสธ (`AppServiceProvider.php:25-30`) |
| Temporary permission | มี `user_delegations` (start_date/end_date/is_active) ใช้ใน `ApprovalController` — **ผ่านบางส่วน** |
| IDOR risk | **สูงมาก** — ดู SEC-003, SEC-005, SEC-006 |
| Enumeration risk | สูง — public id เป็น auto-increment ทุกตาราง |
| Frontend authorization | `router.beforeEach` เช็ค `meta.permission` (`frontend/src/router/index.js:245-263`) — เป็น UX guard เท่านั้น ไม่มีผลด้าน security และ backend ไม่ได้ป้องกันคู่กันทุกเส้น |

---

## 9. Security Assessment

### Security Findings

| ID | Severity | Finding | Evidence | Recommended Action |
|---|---|---|---|---|
| SEC-001 | **CRITICAL** | ไฟล์ใน `storage/app/public` เปิดสาธารณะทั้งหมด ไม่ต้อง login — รวม **ลายเซ็นบุคลากร**, **ภาพเซลฟี่ลงเวลา**, ไฟล์แนบสารบรรณ | `routes/api.php:263-273` (`GET /api/storage/{path}` อยู่นอก `auth:sanctum`) + `docker/nginx/default.conf:14-18` (`location /storage` alias ตรง) + `LeaveController.php:270` และ `ProfileController.php:63,73` เขียนลายเซ็นลง disk `public` + `AttendanceController.php:69` เขียนเซลฟี่ลง disk `public` | ย้ายทุกไฟล์บุคคลไป disk `local` (private) + endpoint ดาวน์โหลดที่ตรวจสิทธิ์; ลบ route `/api/storage/{path}`; ลบ `location /storage` ใน nginx |
| SEC-002 | **CRITICAL** | Remote Code Execution ที่เป็นไปได้: `AttendanceController::checkIn` รับ base64 แล้วเอา **นามสกุลไฟล์จาก MIME ที่ผู้ใช้ส่งมา** ไปเขียนลง public storage โดยไม่มี whitelist — ส่ง `data:image/php;base64,...` จะได้ไฟล์ `.php`; nginx `location ~ \.php$` (regex) ชนะ `location /storage` (prefix) จึงส่งไป FastCGI ผ่าน symlink `public/storage` | `AttendanceController.php:63-73` (ไม่มี `in_array($image_type, [...])` — ต่างจาก `LeaveController.php:267` ที่มี whitelist), `backend/public/storage -> /var/www/html/storage/app/public`, `docker/nginx/default.conf:20-28` | whitelist นามสกุล + ตรวจ MIME จริงด้วย `finfo` + เก็บ private + ตั้ง `location ^~ /storage` และปิดการรัน PHP ในโฟลเดอร์อัปโหลด |
| SEC-003 | **CRITICAL** | IDOR — ดาวน์โหลดใบลาของ **ใครก็ได้**: `GET /api/leaves/{leave}/pdf` และ `/word` ไม่มีการตรวจ ownership หรือ permission ใด ๆ ทั้งสิ้น (PDF มีชื่อ-สกุล, ตำแหน่ง, เหตุผลการลาป่วย, ที่อยู่ติดต่อ, ลายเซ็น) | `routes/api.php:64-65` (อยู่ใน group ที่มีแค่ `auth:sanctum`), `LeaveController::exportPdf:623` และ `exportWord:748` — ทั้งสองเมธอดเริ่มด้วย `$leave->load(...)` ทันที ไม่มี authorize | เพิ่ม Policy `LeavePolicy::view` + `$this->authorize('view', $leave)` และเขียน IDOR test |
| SEC-004 | **CRITICAL** | Broken Access Control — `POST /api/leaves/{leave}/hr-verify` ไม่มีการตรวจสิทธิ์เลย ผู้ใช้ทั่วไปรับรองสถิติการลาของใครก็ได้ | `LeaveController::hrVerify:499-512` — บรรทัดแรกคือ `$leave->hr_verified_by = $request->user()->id;` | บังคับ `can:leave.approval.hr` |
| SEC-005 | **CRITICAL** | IDOR + ข้ามชั้นความลับใน Saraban — `GET /api/saraban/documents/{document}` คืนเอกสารทุกฉบับรวม `secrecy` = `secret`/`top_secret`/`most_secret` โดยไม่ตรวจสิทธิ์; middleware `CanViewClassifiedDocument` **เขียนไว้แต่ไม่เคยลงทะเบียนใน `bootstrap/app.php` และไม่เคยผูกกับ route ใดเลย (dead code)** | `Api/Saraban/DocumentController::show:229-244`, `app/Http/Middleware/CanViewClassifiedDocument.php` (grep ทั้ง repo พบเฉพาะไฟล์ตัวเอง), `routes/api.php:213-230` (group มีแค่ `auth:sanctum` ซ้ำ) | ผูก middleware + Policy ตาม receivers/creator/สิทธิ์สารบรรณ + IDOR test |
| SEC-006 | **CRITICAL** | ลบเอกสารสารบรรณได้โดยไม่มีสิทธิ์ — `DELETE /api/saraban/documents/bulk` และ `DELETE /api/saraban/documents/{document}` ไม่มี authorization | `DocumentController::destroyMultiple:91-101` (มีแต่ `validate`) และ `destroy:353-357` (`$document->delete(); return 204;`) | บังคับ `can:saraban.document.delete` + ownership/scope + audit |
| SEC-007 | **HIGH** | ลายเซ็นอิเล็กทรอนิกส์ข้ามการยืนยันรหัสผ่านได้ — `mode` มาจาก client และถ้าไม่ใช่ `'esignature'` ระบบจะ **ไม่ตรวจรหัสผ่านเลย**; นอกจากนี้ `$isCreator` ทำให้ผู้สร้างเอกสารลงนามเอกสารของตัวเองได้ และไม่มีการตรวจว่าเป็นผู้รับผิดชอบ workflow step ปัจจุบันหรือไม่ | `Api/Saraban/SignatureController::sign:36-40` (`$passwordToVerify = $mode === 'esignature' ? $password : null;`) และ `:26-33` | บังคับยืนยันตัวตนทุก mode + ตรวจว่าเป็น assignee ของ step ปัจจุบัน + ตัด `$isCreator` |
| SEC-008 | **HIGH** | ตรวจสอบลายเซ็นแบบ QR คืน `'is_valid' => true` **แบบ hard-code** ไม่ได้คำนวณ hash ของเอกสารซ้ำ → เอกสารถูกแก้หลังลงนามก็ยังขึ้นว่า "ถูกต้อง" | `Api/Saraban/SignatureController::verify:103-124` | คำนวณ content hash ใหม่แล้วเทียบ (แบบเดียวกับที่ Leave ทำใน `LeaveVerificationService`) |
| SEC-009 | **HIGH** | Export ข้อมูลลงเวลาทั้งองค์กรโดยไม่ต้องมีสิทธิ์ — 7 endpoint อยู่ **นอก** `auth:sanctum` group และรับ token จาก query string; ตรวจแค่ว่า token ใช้ได้ ไม่ตรวจ `attendance.view_all` | `routes/api.php:246-254`, `AttendanceController::previewSummary:763-767`, `exportSummaryPdf:789-793`, `exportOTSummaryPdf:1043-1046` ฯลฯ | ย้ายกลับเข้า `auth:sanctum` + `can:attendance.view_all`; ใช้ short-lived signed URL แทน token ใน query |
| SEC-010 | **HIGH** | Secret รั่วใน repo — `backend/public/test_db.php` มี DB credentials `leave_user / secret` แบบ hard-code และเข้าถึงได้จากเว็บ; `backend/public/info.php` เรียก `phpinfo()` เปิดเผยตัวแปรสภาพแวดล้อมทั้งหมด | `backend/public/info.php`, `backend/public/test_db.php`, `backend/public/test.php` | ลบทั้ง 3 ไฟล์ + หมุนรหัสผ่าน DB |
| SEC-011 | **HIGH** | Endpoint เขียน DB โดยไม่ต้อง login — `GET /fix-leaves` วนแก้ `document_number` ของใบลาทุกใบที่ยังว่าง | `routes/web.php:8-30` | ลบทิ้ง (มี artisan command `FixLeaveDocumentNumbers` อยู่แล้ว) |
| SEC-012 | **HIGH** | Bearer token ไม่มีวันหมดอายุ + เก็บใน `localStorage` + ถูกส่งใน URL → XSS หนึ่งครั้ง = ยึดบัญชีถาวร และ token ติดอยู่ใน access log/referrer | `config/sanctum.php:46`, `frontend/src/stores/auth.js:18`, `frontend/src/views/admin/AttendanceSummaryView.vue:267` | ตั้ง `expiration` + refresh flow; เลิกส่ง token ใน query |
| SEC-013 | **MEDIUM** | XSS sanitizer เป็น blacklist ที่หลบง่าย — ตัดแค่ `<script>` กับ `javascript:` แล้ว `$request->merge()` ทับ input ทุก request (แก้ข้อมูลผู้ใช้โดยไม่ตั้งใจได้ด้วย) | `app/Http/Middleware/SanitizeInput.php:16-30` | ใช้ HTML purifier ที่ output/escape ตอนแสดงผลแทน; `v-html` ที่ `frontend/src/views/admin/DocumentTemplatesView.vue:151` เป็นจุดเสี่ยงคู่กัน |
| SEC-014 | **MEDIUM** | ไม่มี security headers (CSP, HSTS, X-Frame-Options, X-Content-Type-Options) ที่ระดับ app หรือ nginx | `docker/nginx/default.conf` (มีแค่ `Cache-Control`), ไม่มี middleware headers | เพิ่ม middleware + nginx headers |
| SEC-015 | **MEDIUM** | MySQL อนุญาต root ไม่มีรหัสผ่าน | `docker-compose.yml:7` (`MYSQL_ALLOW_EMPTY_PASSWORD: 'yes'`), `backend/.env:26-27` (`DB_USERNAME=root`, `DB_PASSWORD=`) | ตั้งรหัส + user เฉพาะแอป + secret management |
| SEC-016 | **MEDIUM** | ไม่มี audit ในการเปลี่ยนสิทธิ์/บทบาท/ผู้ใช้/การตั้งค่า/การลงเวลา — grep `audit` ใน `Admin\UserController`, `Admin\PermissionController`, `Api\RoleController`, `AttendanceController`, `SecureSettingController` = ไม่พบเลย | ดูข้างบน | เพิ่ม audit กลาง (ผูกกับ DB-RISK-010) |
| SEC-017 | **MEDIUM** | `APP_DEBUG=true`, `APP_ENV=local` ใน `.env` ที่ใช้งาน — ถ้าค่านี้หลุดขึ้น production stack trace จะเปิดเผย credentials | `backend/.env:2-4` | บังคับ env validation ตอน boot |
| SEC-018 | LOW | ไม่มี CSRF protection เพราะเป็น token-based ล้วน — ยอมรับได้ แต่ `sanctum.stateful` ยังเปิดอยู่ ต้องยืนยันว่าไม่มี cookie-auth path | `config/sanctum.php:19,35` | ยืนยันแล้วปิดสิ่งที่ไม่ใช้ |
| SEC-019 | LOW | ไม่มี rate limit เฉพาะจุดอ่อนไหว (export, ดาวน์โหลดเอกสาร) — มีแค่ throttle รวม 60/min | `routes/api.php:35` | เพิ่ม throttle รายกลุ่ม |

> **สรุปความรุนแรง:** CRITICAL 6, HIGH 6, MEDIUM 5, LOW 2

---

## 10. Existing Business Rules ที่ Hard-code

| ประเภท | ที่พบ | หลักฐาน |
|---|---|---|
| Role | ชื่อ role ภาษาไทย/อังกฤษ ฝังใน controller 31 จุด และใน Pinia store อีกชุด | `LeaveController.php:30-36,44,165-171,365-368`, `ApprovalController.php:25-26,73`, `DashboardController`, `ReportController`, `LeaveCardController`, `MenuService`, `frontend/src/stores/auth.js:26-56` |
| Super Admin bypass | `Gate::before` ให้ผ่านทุก ability | `AppServiceProvider.php:25-30` |
| Approval step | `approval_steps` global + `leaves.approval_level` เป็น integer | `2026_06_14_000001_create_approval_steps_table.php`, `ApprovalController::approve:105-120` |
| ผู้รับแจ้งเตือนใบลาใหม่ | รายชื่อ role ฝังในโค้ด | `LeaveController.php:291-299` |
| ผู้ลงนามในแบบฟอร์ม | `$roleSettingMap = ['HR Head'=>'head_hr_name','Deputy Director'=>'deputy_admin_name','Director'=>'director_name']` | `LeaveController.php:637-641` และซ้ำที่ `:762-766` |
| ผู้มีสิทธิ์ลงนามสารบรรณ | รายชื่อ role ฝังในโค้ด | `Api/Saraban/SignatureController.php:26-33` |
| ผู้เห็นเอกสารสารบรรณทั้งหมด | `['Super Admin','SuperAdmin','admin_saraban','Saraban Officer','เจ้าหน้าที่สารบัญ']` (สะกดไม่ตรงกับ role จริง `เจ้าหน้าที่สารบรรณกลาง` ใน frontend) | `Api/Saraban/DocumentController.php:38` และ `:420` vs `frontend/src/stores/auth.js:31` |
| Menu visibility | `MenuService` ใช้ role name | `app/Services/MenuService.php` |
| Leave entitlement | **ไม่ hard-code แล้ว** — ย้ายเข้า `leave_policies` เรียบร้อย (จุดแข็ง) | `LeavePolicyService`, `leave_policies` table |
| Work schedule | `users.work_start_time` + fallback `default_work_start_time` ใน settings — configurable ระดับบุคคลแล้ว (จุดแข็งบางส่วน) | `AttendanceController.php:80` |
| Grace period | **ไม่มี** — สายทันทีที่เกินเวลา 1 วินาที | `AttendanceController.php:82-86` |

---

## 11. Integrations

| Integration | สถานะ | หลักฐาน |
|---|---|---|
| **Face/Fingerprint vendor system** | **ไม่มีเลย** — grep `zkteco\|hikvision\|biometric\|device_id\|scanner` ทั่ว `app/`, `database/`, `routes/`, `config/` ไม่พบสิ่งใดที่เกี่ยวข้อง (ผลลัพธ์ที่เจอเป็นคำว่า "ลายนิ้วมือ" ในคอมเมนต์ที่หมายถึง content hash ของใบลา) | grep ทั้ง repo |
| LINE OA | มี — LINE Messaging API `POST /v2/bot/message/push` แบบ synchronous, ไม่มี retry/backoff/delivery log | `app/Services/LineNotificationService.php:11-43`, `AttendanceController::sendLineMessage:911-945` |
| Telegram | มี — 2 service ซ้ำซ้อนกัน (`TelegramService` + `TelegramNotificationService`) | `app/Services/Telegram*.php` |
| Email | มี — SMTP config มาจากตาราง `settings` ตอน boot | `AppServiceProvider.php:32-52` |
| In-App | มี — `notifications` table + `NotificationController` | `2026_06_21_213300_create_notifications_table.php` |
| File storage | Local disk เท่านั้น — ไม่มี MinIO/S3 (config มี AWS driver แต่ `.env` ว่าง) | `config/filesystems.php`, `backend/.env` |
| External identity provider | ไม่มี | — |

### 11.1 โครงสร้างเครื่องสแกนตามที่ผู้ใช้อธิบาย vs ที่มีจริง

```text
ที่ผู้ใช้อธิบาย:  เครื่องสแกนใบหน้า/ลายนิ้วมือ → ระบบผู้ผลิต → ระบบเดิม
ที่มีจริงในโค้ด:  ยังไม่มีขั้นตอนใดเลย
ที่ระบบเดิมทำได้:  พนักงานเปิดเว็บ → กด check-in → ส่ง GPS + ภาพเซลฟี่ base64 → เขียน attendances 1 แถว/วัน
```

| หัวข้อที่ต้องบันทึก | ค่า |
|---|---|
| Vendor/system name | **UNKNOWN — ต้องถามผู้ใช้** |
| วิธีส่งข้อมูล (API/DB/File/Webhook/Polling) | **UNKNOWN — ต้องถามผู้ใช้** |
| Employee identifier ที่ใช้ mapping | **UNKNOWN** (ระบบเดิมมีเพียง `users.id`, `users.email`, `user_profiles.id_card_number_hash` เป็นตัวเลือก) |
| Device identifier | **ไม่มีที่เก็บในระบบเดิม** |
| Timestamp format | **UNKNOWN** — ระบบเดิมใช้ server time (`Carbon::now()`) เป็น source of truth ซึ่งตรงกับ `PROJECT_RULES` ข้อ 9 |
| Sync frequency | ไม่มี |
| Duplicate protection | ไม่มี (มีเพียง `unique(user_id,date)` ที่จะทำให้ event ที่สองของวันชนกัน) |
| Retry/reconciliation | ไม่มี |

> **นี่คือ Open Question ที่ต้องได้คำตอบก่อนวางแผน Phase LEGACY-08**

---

## 12. UI/UX Assessment

| หัวข้อ | ผลตรวจ |
|---|---|
| ภาษาไทย | ข้อความผู้ใช้เป็นไทยเกือบทั้งหมด (ทั้ง frontend และ error message จาก backend) — **ผ่านเป็นส่วนใหญ่**; ยังพบข้อความอังกฤษหลุดจาก API เช่น `'Documents deleted successfully'`, `'Document marked as read'`, `'Failed to update document: '.$e->getMessage()` (`Api/Saraban/DocumentController.php:99,349,378`) |
| Mobile/Tablet/Desktop | ใช้ Tailwind 4 — น่าจะ responsive แต่ **ยืนยันไม่ได้โดยไม่รันแอป** = `UNKNOWN` |
| Accessibility | ไม่พบร่องรอยการทำ a11y (ไม่มี aria/label convention ที่เป็นระบบ) — **ไม่ผ่าน** |
| Form validation | มี validation ฝั่ง server (`$request->validate`) แต่ `FormRequest` ที่มีอยู่ 2 ไฟล์ — `StoreLeaveRequest` **`authorize()` return `false` และ `rules()` ว่างเปล่า** (ไฟล์ scaffold ที่ไม่ได้ใช้) | `app/Http/Requests/StoreLeaveRequest.php:14-27` |
| Loading/Empty/Error state | มีบางหน้า — ไม่ได้ตรวจครบทั้ง 43 view = `UNKNOWN` |
| Error ที่รั่วสู่ผู้ใช้ | มี — `return response()->json(['message' => 'Failed to update document: ' . $e->getMessage()], 500)` เปิดเผยรายละเอียดภายใน | `Api/Saraban/DocumentController.php:349` |
| Permission-aware navigation | มี (`MenuService` + `menus` table + `router.beforeEach`) แต่ผูกกับ role name |

---

## 13. Testing Assessment

| หัวข้อ | ผลตรวจ |
|---|---|
| Unit tests | 2 ไฟล์ (`ExampleTest`, `UserTest`) = 8 test |
| Integration/Feature tests | 16 ไฟล์ ≈ 78 test — ครอบคลุมดีในโดเมน Leave ราชการ (`LeaveEligibilityRulesTest` 8, `LeaveEntitlementRulesTest` 8, `PersonalDataProtectionTest` 13, `PromotionScreeningTest` 7, `LeaveVerificationTest` 6) |
| E2E | **ไม่มี** |
| Authorization tests | มีทางอ้อมบางส่วนใน `PersonalDataProtectionTest`; **ไม่มี role × scope × action matrix** |
| IDOR tests | **ไม่มีเลย** — และช่องโหว่ SEC-003/005/006 ยืนยันว่าไม่เคยถูกทดสอบ |
| Attendance/OT tests | **ไม่มีเลย** (0 test สำหรับ controller 1,137 บรรทัด) |
| Saraban tests | 1 test (`Saraban/DocumentTest::test_user_can_create_document`) สำหรับ 9 controller |
| Migration tests | ไม่มี — และรันบน SQLite ไม่ใช่ MySQL (DB-RISK-008) |
| Frontend tests / lint / typecheck | **ไม่มีทั้งหมด** — `frontend/package.json` มี scripts แค่ `dev`, `build`, `preview`; ไม่มี TypeScript |
| ผลรันล่าสุด | **ไม่ได้รันในรอบนี้** (ไม่มี `php` ในสภาพแวดล้อม) — `backend/.phpunit.result.cache` (เขียนเมื่อ 2026-08-11 21:09) มี defect entries **42 รายการ** สถานะ 7/8 (ระดับ warning/failure) ครอบคลุม `AuthTest`, `LeaveTest`, `ApprovalTest`, `UserTest`, `LeaveExportTest`, `Saraban\DocumentTest` ฯลฯ |

> ⚠️ **ต้องยืนยันก่อนอนุมัติ:** ถ้าผลนี้จริง แปลว่า **ประมาณครึ่งหนึ่งของ test suite ไม่ผ่านอยู่ ณ baseline** ซึ่งขัดกับ `AGENTS.md` ("ห้าม bypass tests") และทำให้ Phase LEGACY-00 ต้องมีงาน "ทำให้ test เขียวก่อน" เป็นอันดับแรก รัน `php artisan test` แล้วบันทึกผลจริงลงเอกสารนี้

---

## 14. Deployment Assessment

| หัวข้อ | ผลตรวจ |
|---|---|
| Docker | มี **2 stack ที่ไม่สอดคล้องกัน**: (ก) `docker-compose.yml` = db + backend (`php artisan serve`) + frontend (`vite dev`) (ข) root `Dockerfile` (php-fpm) + `docker/nginx/default.conf` ที่ **ไม่มี service ไหนอ้างถึงเลย** |
| Web server | `php artisan serve` — **dev server ของ Laravel** ไม่เหมาะกับ production (single-threaded, ไม่มี TLS) |
| Environment separation | ไม่มี — `.env` เดียว `APP_ENV=local`, `APP_DEBUG=true`; ไม่มี `.env.staging`/`.env.production`; `.env.testing` ถูก commit เข้า git |
| Secrets | เก็บใน `.env` (ignored ถูกต้อง) แต่ credentials โผล่ใน `docker-compose.yml` และ `backend/public/test_db.php` |
| Queue worker | **ไม่มี** — notification ที่เป็น `ShouldQueue` จะค้างในตาราง `jobs` ไม่ถูกส่ง |
| Scheduler | **ไม่มี** — `routes/console.php` ประกาศ 4 งาน (`backup:run` 02:00, `leave:governance` รายชั่วโมง, purge รายเดือน, `app:carry-forward-leaves` 1 ต.ค.) แต่ไม่มี container รัน `schedule:work` |
| Backup | **ล้มเหลวเชิงโครงสร้าง** — `Schedule::command('backup:run')` แต่ `spatie/laravel-backup` **ไม่อยู่ใน `composer.json`** เลย → คำสั่งไม่มีอยู่จริง; ถึงมี scheduler ก็จะ error ทุกคืน |
| Restore | ไม่มีขั้นตอน/สคริปต์/เอกสาร |
| Health checks | Laravel `health: '/up'` เปิดไว้ใน `bootstrap/app.php:11`; MySQL มี healthcheck; backend/frontend ไม่มี |
| Logging | `LOG_CHANNEL=stack`, `LOG_LEVEL=debug`, single file — ไม่มี structured logging, ไม่มี correlation ID |
| Monitoring | ไม่มี |
| Rollback | ไม่มีกลไก; migration หลายตัวมี `down()` ที่ drop table (อันตรายถ้าเผลอรัน) |
| Redis / MinIO / Nginx / worker / backup container | **ไม่มีทั้งหมด** (ต่างจาก `docs/16-DEPLOYMENT.md` ที่กำหนด web, worker, postgres, redis, minio, nginx, backup) |

### 14.1 สถานะ Repository ที่ต้องจัดการก่อน (Baseline hygiene)

| ปัญหา | หลักฐาน |
|---|---|
| Working tree มีการแก้ไขค้าง **60+ ไฟล์** ที่ยังไม่ commit (รวม controller/model/service/test หลัก) | `git status --short` ที่ `D:\E-Le` |
| commit ล่าสุด 2 อันชื่อ `"update"` ทั้งคู่ — ไม่มีประวัติที่สืบย้อนได้ | `git log` |
| ชุดเอกสาร E-la (`START-HERE.md`, `AGENTS.md`, `docs/*`) **ยังไม่ถูก commit** เข้า git เลย | `git status` แสดงเป็น untracked/modified |
| มี git worktree `.claude/worktrees/legacy-upgrade-prompt-766577` ที่ checkout อยู่ที่ commit เก่า และ **ไม่มีชุดเอกสารนี้** — การประเมินทั้งหมดจึงทำบน `D:\E-Le` | `git worktree`, `ls` เปรียบเทียบ |
| ไฟล์ dev/debug หลุดเข้า repo: `backend/debug.php`, `fix.php`, `fix_hash.php`, `sqlite_test.php`, `seed_balances.php`, `sync_test_accounts.php`, `test_api_call.php`, `test_login.php`, `public/info.php`, `public/test.php`, `public/test_db.php`, `backend/eleave` (SQLite DB) | `ls backend/` |
| ไม่มี `.gitignore` ที่ root | `ls -la D:\E-Le` |
| ไม่มี CI/CD (`.github/workflows` ไม่มี) | — |

---

## 15. Keep / Refactor / Replace

### KEEP — ใช้ได้ดี ปลอดภัย และสอดคล้อง Target Architecture

| รายการ | เหตุผล | หลักฐาน |
|---|---|---|
| **Leave Policy Engine** | policy แยกตาม `personnel_type × leave_type` + `effective_from/to` + `leaves` pin `leave_policy_id` — ตรงกับ ADR-002 แทบทั้งหมด | `2026_08_08_100000`, `app/Services/LeavePolicyService.php` |
| **Leave Eligibility Rules** | กฎเฉพาะประเภทลาแยกเป็น data (`leave_eligibility_rules`) พร้อม test 8 ตัว | `LeaveEligibilityRuleService`, `LeaveEligibilityRulesTest` |
| **Leave Evidence (private)** | เก็บ disk `local`, มี `sha256`, มี verification workflow, download ตรวจสิทธิ์ + `Cache-Control: private, no-store` | `LeaveEvidenceController` ทั้งไฟล์ |
| **Leave verification hash + QR** | `leaves.content_hash` ผนึกตอนอนุมัติ ตรวจการแก้ย้อนหลังได้ + rate limit 30/min บน endpoint สาธารณะ | `LeaveVerificationService`, `routes/api.php:27-31` |
| **Leave / Saraban document sequence** | atomic ด้วย transaction + `lockForUpdate()` + unique constraint | `LeaveDocumentNumberService`, `Saraban\DocumentNumberGeneratorService` |
| **Personnel types + fiscal years + holidays + work calendar** | เป็น data-driven แล้ว ไม่ hard-code | `personnel_types`, `fiscal_years`, `holidays`, `work_calendar_exceptions` |
| **PDPA layer** | `personal_data_access_logs`, สิทธิขอสำเนาข้อมูลตนเอง, purge command, encrypted casts (`id_card_number`, `passport_number`, `phone`) + 13 test | `PersonalDataController`, `PersonalDataAccessLogger`, `PersonalDataProtectionTest`, `UserProfile.php:55-75` |
| **Government report suite** | บัตรลา, ทะเบียนลา, สรุปเลื่อนเงินเดือน — ตรงความต้องการราชการและมี test | `LeaveCardController`, `GovernmentLeaveReportController`, `PromotionScreeningController` |
| **routes/leave_government.php authorization pattern** | ทุก route มี `can:` ชัดเจน + controller ใช้ `abort_unless` ตรวจ ownership — **นี่คือแพทเทิร์นที่ควรใช้กับทั้งระบบ** | ไฟล์ทั้งไฟล์ |
| **ThaiDateService** | แปลง พ.ศ. ที่ชั้น presentation, เก็บ ค.ศ. ใน DB — ถูกต้องตามหลัก | `app/Services/ThaiDateService.php` |
| **Spatie laravel-permission** | เป็นฐานที่ถูกต้อง ต่อยอด ABAC ได้ | `composer.json` |
| **User delegation** | มี start/end/is_active ใช้ในการอนุมัติแทน | `user_delegations`, `ApprovalController:47-61` |

### REFACTOR — ยังใช้ได้แต่ต้องปรับ

| รายการ | ต้องทำอะไร |
|---|---|
| Authorization ทั้งระบบ | เลิก role-name hard-code 31 จุด → permission + scope; ลบ `Gate::before` แบบไร้เงื่อนไข; รวม permission ให้เหลือรูปแบบเดียว `MODULE.RESOURCE.ACTION` โดยมี alias adapter ระหว่างทาง |
| `LeaveController` (888 บรรทัด) | แตกเป็น service + Policy + FormRequest; ปิด IDOR (SEC-003/004); ลบตรรกะซ้ำระหว่าง `exportPdf`/`exportWord` |
| `AttendanceController` (1,137 บรรทัด) | แตกเป็น service; ปิด SEC-002/009; แยก preview/export ออกจาก controller |
| Organization | เพิ่ม `org_units` แบบ hierarchical (additive) แล้ว map จาก `departments` |
| Personnel | เพิ่ม assignment history + effective dating |
| File storage | ทุกไฟล์บุคคล → private + authorized download endpoint |
| Notification | เข้าคิวจริง + template + retry/backoff + dead-letter + delivery log; ต้องไม่ทำให้ transaction หลัก rollback |
| Audit | รวมเป็น `audit_logs` กลาง + ครอบคลุม users/roles/permissions/settings/attendance |
| Document template | เพิ่ม version + effective dating + pin ที่ record |
| Deployment | เลิก `php artisan serve`; เพิ่ม nginx/php-fpm, worker, scheduler, redis, backup container; แยก env |
| Testing | เพิ่ม IDOR/authorization matrix, attendance, Saraban; รัน migration test บน MySQL; เพิ่ม lint/build ฝั่ง frontend |
| Frontend auth store | เลิกเช็ค role name; ใช้ permission ที่ backend ส่งมาเท่านั้น; ย้าย token ออกจาก URL |

### REPLACE — แก้อย่างปลอดภัยไม่ได้ / ต้นทุนสูงกว่าการเขียนใหม่

| รายการ | เหตุผล |
|---|---|
| **Workflow/Approval engine** (`approval_steps` + `leaves.approval_level` + ตรรกะใน `ApprovalController`/`GovernmentApprovalController`) | โครงสร้างไม่รองรับ version, parallel, conditional, escalation, timeout; และ `approval_steps` ผูกกับ `roles` legacy ที่กำลังจะเลิกใช้ — ต้องสร้าง engine ใหม่ตาม `docs/07` แล้ว migrate ประวัติ (`leave_approvals` เก็บไว้ทั้งหมด อ่านอย่างเดียว) |
| **Attendance ingestion path** | ปัจจุบันเขียน `attendances` ตรงจาก request; ต้องแทนด้วย raw event → normalize → engine ตาม ADR-003; ข้อมูลเดิมยังเก็บไว้เป็น attendance ที่ถือว่า reconcile แล้ว |
| **`SanitizeInput` middleware** | blacklist ที่หลบง่ายและแก้ข้อมูลผู้ใช้โดยไม่ตั้งใจ — ถอดออกแล้วแทนด้วย escape ที่ output + validation ที่ input |
| **`backend/public/*.php` (info/test/test_db) และ `routes/web.php::/fix-leaves`** | ลบทิ้ง ไม่มีคุณค่าทางธุรกิจ |
| **`users.role_id` + ตาราง `roles` legacy** | ทำให้เกิด DB-RISK-002; ต้องเลิกใช้หลังมี compat layer |

---

## 16. Critical Findings (ต้องแก้ก่อนเพิ่ม Feature ใหม่)

| # | Finding | อ้างอิง | Priority |
|---|---|---|---|
| 1 | Schema จริงอาจไม่ตรงกับ migration (มีร่องรอย DDL timeout กลางคัน) — **ต้องตรวจก่อนแตะอะไรทั้งสิ้น** | DB-RISK-006 | **P0** |
| 2 | ระบบไม่มี backup ที่ทำงานจริง (`backup:run` ไม่มี package + ไม่มี scheduler) | §14 | **P0** |
| 3 | ไฟล์ส่วนบุคคล (ลายเซ็น, เซลฟี่, ไฟล์แนบ) เปิดสาธารณะไม่ต้อง login | SEC-001 | **P0** |
| 4 | ช่องทางอัปโหลดที่อาจนำไปสู่ RCE ผ่านนามสกุลไฟล์ที่ผู้ใช้ควบคุม | SEC-002 | **P0** |
| 5 | IDOR — ดาวน์โหลดใบลาของผู้อื่นได้ทั้งระบบ | SEC-003 | **P0** |
| 6 | เอกสารลับสารบรรณเปิดให้ผู้ใช้ทุกคนอ่าน/ลบ (middleware เป็น dead code) | SEC-005, SEC-006 | **P0** |
| 7 | ลายเซ็นอิเล็กทรอนิกส์ข้ามการยืนยันตัวตนได้ และ verify คืน valid เสมอ | SEC-007, SEC-008 | **P0** |
| 8 | Secret รั่วในไฟล์ที่เข้าถึงได้จากเว็บ + endpoint เขียน DB โดยไม่ต้อง login | SEC-010, SEC-011 | **P0** |
| 9 | ระบบ role สองชุดพร้อมกัน — สิทธิ์อาจผิดตัวและโค้ดอาจ crash | DB-RISK-002 | **P1** |
| 10 | Notification/scheduled job ไม่ทำงานจริงเพราะไม่มี worker/scheduler (ใบลาอาจไม่แจ้งเตือน, carry-forward ไม่รัน) | §14 | **P1** |
| 11 | ประมาณครึ่งหนึ่งของ test suite ไม่ผ่านที่ baseline (ต้องยืนยัน) | §13 | **P1** |
| 12 | ไม่มี org hierarchy → ทำ ABAC scope ไม่ได้ | DB-RISK-001 | **P1** |

---

## 17. Assessment Conclusion

### ระบบเดิมพร้อม Upgrade ระดับใด

**พร้อมสำหรับ Incremental Modernization — ไม่ต้อง rewrite**

ระบบนี้ไม่ใช่ระบบที่ควรรื้อ เพราะชั้น "Leave ราชการ" ที่พัฒนาช่วงหลัง (สิงหาคม 2569) มีคุณภาพสูงจริง: policy engine แบบ effective-dated, evidence integrity ด้วย sha256, PDPA layer พร้อม access log, การอนุญาตที่ทำถูกต้องใน `leave_government.php` และ test ที่ครอบคลุมกฎราชการ — สิ่งเหล่านี้แพงและใช้เวลานานกว่าจะสร้างใหม่ได้

ปัญหาคือระบบมี **ชั้นคุณภาพสองระดับซ้อนกัน**: ชั้นใหม่ที่ดี กับชั้นเก่า (`LeaveController`, `AttendanceController`, `Saraban/*`) ที่แทบไม่มี authorization เลย และชั้นเก่านั้นแหละที่เปิดช่องโหว่ระดับ CRITICAL 6 รายการอยู่ในขณะนี้

**ข้อสรุปสำคัญที่สุด:** ระบบนี้ **ยังไม่ควรใช้งานจริงบนอินเทอร์เน็ตสาธารณะ** จนกว่าจะปิด SEC-001 ถึง SEC-011 — ไม่ใช่เพราะสถาปัตยกรรมผิด แต่เพราะข้อมูลส่วนบุคคล (ลายเซ็น ภาพใบหน้า เหตุผลการลาป่วย) กำลังเปิดให้เข้าถึงได้โดยไม่ต้องยืนยันตัวตน

### จุดเสี่ยงหลัก

1. **Schema drift** — มีหลักฐานว่า migration เคยรันไม่จบ ทุกแผนหลังจากนี้ตั้งอยู่บนสมมติฐานที่ยังไม่ได้ยืนยัน
2. **ไม่มี backup ที่ทำงาน** — ถ้าเสียหายตอนนี้ กู้ไม่ได้ นี่คือเหตุผลที่ Phase LEGACY-00 ต้องมาก่อนทุกอย่าง
3. **Authorization ผูกกับชื่อ role ภาษาไทย** — แก้ชื่อ role หนึ่งครั้งในหน้าจอ admin ทำให้สิทธิ์ทั้งระบบพัง และมี 31 จุดที่ต้องแก้พร้อมกัน
4. **Role สองระบบซ้อนกัน** — `users.role_id` กับ Spatie ถูกนำ id มาปนกันในโค้ดจริง
5. **Test แดงที่ baseline** — ทำให้ไม่มีตาข่ายนิรภัยสำหรับการ refactor

### ข้อมูลเดิมที่ต้องรักษา (ห้ามสูญหาย)

`users`, `user_profiles` (เข้ารหัสอยู่), `departments`, `personnel_types`, `leaves` (+ soft-deleted), `leave_approvals`, `leave_balances`, `leave_policies`, `leave_evidences` + ไฟล์ใน `storage/app/private`, `leave_audit_logs`, `leave_action_logs`, `attendances`, `saraban_documents` + attachments + signatures + `saraban_audit_logs`, `personal_data_access_logs`, `notifications`, `settings`, `fiscal_years`, `holidays`, sequence tables ทั้งสองตัว

### ลำดับงานที่แนะนำ

```text
LEGACY-00  Freeze & Baseline      ← ยืนยัน schema จริง + ทำ backup ที่ใช้ได้จริง + บันทึกผล test จริง
LEGACY-01  Stabilization          ← ทำให้ test เขียว + ลบไฟล์ debug/endpoint อันตราย
LEGACY-02  Security Hardening     ← ปิด SEC-001..SEC-012  (ห้ามข้าม ห้ามทำหลัง feature)
LEGACY-03  Core Data Foundation   ← org hierarchy, UUID, personnel history
LEGACY-04  RBAC + ABAC            ← เลิก role hard-code, รวม permission, scope engine
LEGACY-05  Workflow Engine        ← replace approval_steps
CORE CHECKPOINT
LEGACY-06+ Time / Leave / Attendance integration / OT / Notification / Evaluation
```

### สิ่งที่ห้ามเปลี่ยนทันที

- ❌ ห้ามแตะ `leaves`, `leave_approvals`, `leave_balances`, `leave_policies` schema ก่อน LEGACY-00 ยืนยัน schema จริง
- ❌ ห้ามลบ `users.role_id` หรือตาราง `roles` legacy ก่อนมี compat adapter และยืนยันว่า `approval_steps.role_id` ชี้ไปที่ใด
- ❌ ห้ามเปลี่ยนรูปแบบเลขที่หนังสือ (`leave_document_sequences`, `saraban_document_sequences`) — เอกสารที่พิมพ์ออกไปแล้วอ้างอิงเลขเหล่านี้
- ❌ ห้ามลบ `leave_action_logs` แม้จะซ้ำซ้อนกับ `leave_audit_logs` จนกว่าจะ migrate เข้า audit กลางและ verify แล้ว
- ❌ ห้ามเปลี่ยน authentication เป็น cookie/session ก่อนมี compatibility + rollback plan (frontend ทั้งหมดพึ่ง Bearer token)
- ❌ ห้ามรัน `down()` ของ migration ใด ๆ บน production — หลายตัว drop table

---

## 18. Open Questions (ต้องได้คำตอบจากผู้ใช้ก่อน Phase ถัดไป)

| # | คำถาม | บล็อก Phase ไหน |
|---|---|---|
| 1 | เครื่องสแกนใบหน้า/ลายนิ้วมือที่ใช้อยู่คือยี่ห้อ/รุ่นอะไร และระบบผู้ผลิตให้ข้อมูลออกมาทางไหน (REST API / webhook / อ่าน DB ตรง / ไฟล์ CSV / SFTP)? | LEGACY-08 |
| 2 | ระบบผู้ผลิตใช้อะไรระบุตัวบุคลากร (รหัสพนักงาน / เลขบัตรประชาชน / enrollment id) และจะ map กับ `users` ด้วยฟิลด์ไหน? | LEGACY-08 |
| 3 | ระบบนี้ **ใช้งานจริงอยู่แล้วบน production หรือยัง** และมีข้อมูลจริงกี่ราย? (มีผลต่อความเข้มของ backup/rollback ทุก phase) | LEGACY-00 |
| 4 | `docs/02-TECH-STACK.md` ระบุ Next.js + PostgreSQL แต่ระบบจริงเป็น Laravel + MySQL — **Target Architecture ต้องการให้เปลี่ยน stack จริงหรือไม่?** ถ้าใช่ นี่คือ rewrite ไม่ใช่ upgrade และขัดกับกฎข้อ 1-2 ของ `LEGACY-UPGRADE-PROMPT.md` | ทุก Phase |
| 5 | โมดูลสารบรรณ (Saraban) ที่มีอยู่ — เปิดใช้งานจริงแล้วหรือยัง? มีเอกสารลับในระบบหรือไม่? (มีผลต่อความเร่งด่วนของ SEC-005/006) | LEGACY-02 |
| 6 | running number ของใบลาและหนังสือ ใช้ปีงบประมาณ (1 ต.ค.) หรือปีปฏิทิน พ.ศ.? (โค้ดปัจจุบันใช้ **ปีงบประมาณ** ใน Saraban แต่ Leave ใช้ `periodYear` ตาม policy — อาจไม่ตรงกัน) | LEGACY-07 |
| 7 | ต้องการให้ token session หมดอายุกี่นาที และยอมรับให้ผู้ใช้ต้อง login ใหม่บ่อยขึ้นได้หรือไม่? | LEGACY-02 |

---

## 19. Sign-off

| บทบาท | ชื่อ | วันที่ | ผล |
|---|---|---|---|
| ผู้จัดทำ | AI Agent (Claude Code) | 2026-08-12 | เสร็จรอบที่ 1 |
| ผู้ตรวจทาน | | | ☐ อนุมัติ ☐ ขอแก้ไข |
| ผู้อนุมัติให้เข้าสู่ Legacy Refactor Phase | | | ☐ อนุมัติ ☐ ไม่อนุมัติ |

> **เอกสารนี้ยังไม่ผ่านการอนุมัติ — ห้ามเริ่มแก้ Source Code**
