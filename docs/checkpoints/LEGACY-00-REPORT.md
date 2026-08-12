# LEGACY-00 — Freeze & Baseline Report

**วันที่ตรวจ:** 12 สิงหาคม 2569 (2026-08-12) เวลา ~22:30–23:05
**ขอบเขต:** LEGACY-00 เท่านั้น ตาม `docs/LEGACY-MIGRATION-PLAN.md`
**ผลรวม:** **WARNING** — ทุกรายการที่ตรวจได้ผ่าน แต่ยังมี P0 security 3 รายการที่เปิดอยู่ และ 1 เรื่องที่อยู่นอกมือ

---

## ⚠️ บริบทสำคัญ — นี่คือการ re-baseline ไม่ใช่ baseline ก่อน LEGACY-01

LEGACY-00 เคยทำไปแล้วครั้งหนึ่งและออก tag `baseline/legacy-2026-08-12` ไว้
หลังจากนั้นระบบเปลี่ยนไป **8 commit** ได้แก่ security hotfix 2 รอบ, LEGACY-01 Stabilization
เต็มเฟส และ GATE-01/GATE-02

รายงานฉบับนี้จึงบันทึกสภาพ ณ ปัจจุบัน (`381aa94`) ซึ่ง **อยู่หลัง LEGACY-01 ไปแล้ว**
ไม่ใช่สภาพก่อนเริ่ม modernization

**การตรวจรอบนี้ไม่ได้แก้ไขระบบใด ๆ** — ไม่แตะ business logic, ไม่เพิ่ม feature, ไม่ refactor,
ไม่สร้าง migration, ไม่แก้ schema, ไม่ลบข้อมูล และไม่เริ่ม LEGACY-01/02

> ข้อยกเว้นเดียว: การทดสอบ worker สร้าง failed job ขึ้น 1 แถวจาก probe ของผมเอง
> และผมลบแถวนั้นทิ้งเพื่อคืน baseline ให้ตรงความจริง (บันทึกไว้ใน §12)

---

## สรุปผลรายข้อ

| # | รายการ | ผล |
|---|---|:---:|
| 1 | ตรวจ git baseline | **PASS** |
| 2 | ตรวจ environment/runtime | **WARNING** |
| 3 | `php artisan migrate:status` | **PASS** |
| 4 | ตรวจ schema MySQL จริง | **PASS** |
| 5 | ตรวจ critical tables | **PASS** |
| 6 | บันทึก row count baseline | **PASS** |
| 7 | ทำ database backup | **PASS** |
| 8 | backup `storage/app` | **PASS** |
| 9 | ทดสอบ restore บน instance แยก | **PASS** |
| 10 | `php artisan test` | **PASS** |
| 11 | `npm run build` | **PASS** |
| 12 | ตรวจ queue/worker/scheduler | **PASS** |
| 13 | ยืนยัน P0 security findings | **WARNING** |
| 14 | สร้างรายงานฉบับนี้ | **PASS** |

**ไม่มีรายการใดเป็น FAIL**

---

## 1. Git baseline — PASS

| รายการ | ค่า |
|---|---|
| branch | `main` |
| HEAD | `381aa94` |
| origin/main | `381aa94` — **ตรงกัน** |
| working tree | **สะอาด** ไม่มีไฟล์ค้าง |
| tag | `baseline/legacy-2026-08-12` |
| ระยะจาก tag | 8 commit |

```
381aa94  fix(security): ปิด GATE-01 หมุน credential MySQL และ GATE-02 ปีงบประมาณ
06e01bc  feat(legacy-01): stabilization phase — backup/restore, CI, logging, Voided schema
bc8a1ca  fix(security): ย้ายไฟล์อัปโหลดไป private disk และหมุน APP_KEY ที่รั่ว
123de8e  docs(legacy-00): บันทึกผลการแก้ hotfix และเหตุผลที่ยังไม่ปิด /storage/{path}
f843b3c  fix(security): ปิดช่องเปิดเผยข้อมูลที่เข้าถึงได้จากอินเทอร์เน็ต 4 จุด
89d3c6e  docs(legacy-00): record D-1..D-4 outcomes and verified internet exposure
603b838  docs(legacy-00): freeze & baseline evidence + corrections
61392121 chore(legacy-00): baseline snapshot  ← tag ชี้ที่นี่
```

## 2. Environment / Runtime — WARNING

| รายการ | ค่า | หมายเหตุ |
|---|---|---|
| PHP | 8.4.24 | |
| Laravel | 13.25.0 | |
| MySQL | 8.0.46 | |
| Node | v22.23.2 | |
| DB | `mysql` / `eleave` | |
| DB user ที่แอปใช้จริง | **`eleave_app@%`** | สิทธิ์จำกัด — GATE-01 |
| DB password | ตั้งแล้ว | |
| QUEUE_CONNECTION | `database` | |
| LOG channel | `structured` | LEGACY-01 |
| filesystem default | `local` | |
| `private_uploads` disk | `/var/www/html/storage/app/private/uploads` | |
| `app.previous_keys` | 0 | re-key เสร็จสมบูรณ์ |
| **`APP_ENV`** | **`local`** | ⚠️ ไม่ใช่ `production` ทั้งที่เปิดออกอินเทอร์เน็ต |
| **`APP_DEBUG`** | **`true`** | ⚠️ ดู §13 |
| **`sanctum.expiration`** | **`NULL`** | ⚠️ token ไม่หมดอายุ (SEC-012) |
| **`session.secure`** | **`NULL`** | ⚠️ ไม่มี Secure cookie flag |

### Container

| container | สถานะ | port |
|---|---|---|
| `eleave_db` | Up (healthy) | `127.0.0.1:3306` — ไม่ออกอินเทอร์เน็ต ✓ |
| `eleave_backend` | Up | `0.0.0.0:8001` |
| `eleave_worker` | Up | — |
| `eleave_scheduler` | Up | — |
| `eleave_frontend` | Up | `0.0.0.0:5173` |

**เหตุที่ให้ WARNING:** `APP_DEBUG=true` คู่กับ `APP_ENV=local` บนระบบที่เข้าถึงได้จากอินเทอร์เน็ต
`ProductionEnvironmentGuard` ที่ LEGACY-01 เพิ่มไว้จะหยุด bootstrap **เฉพาะเมื่อ `APP_ENV=production`**
จึงไม่ครอบกรณีนี้ — stack trace ยังหลุดถึงผู้ใช้ได้ถ้าเกิด error

## 3. Migration status — PASS

| รายการ | ค่า |
|---|---|
| บันทึกในตาราง `migrations` | 94 |
| ไฟล์บนดิสก์ | 94 |
| **ค้างยังไม่รัน (pending)** | **0** |
| มีในตารางแต่ไม่มีไฟล์ (ghost) | 0 |
| batch สูงสุด | 14 |

> หมายเหตุ: ผลรันครั้งแรกผมนับผิดเป็น "Pending 1" เพราะ regex ไปจับชื่อ migration
> `2026_08_09_010000_allow_pending_approval_snapshots` ที่มีคำว่า *pending* อยู่ในชื่อ
> ตัวเลขข้างบนนับจากตาราง `migrations` เทียบกับไฟล์จริง ซึ่งเป็นแหล่งที่เชื่อถือได้

**DB-RISK-006 (schema drift จาก DDL timeout) ปิดแล้ว** — ไฟล์กับตารางตรงกันทุกรายการ

## 4. Schema MySQL จริง — PASS

| รายการ | ค่า |
|---|---|
| จำนวนตาราง | 55 |
| engine/collation ผิดมาตรฐาน | **0** (ทุกตารางเป็น InnoDB / utf8mb4_unicode_ci) |
| Foreign keys | 69 |
| Unique constraints | 22 |
| ตารางที่ไม่มี PRIMARY KEY | **0** |

## 5. Critical tables — PASS

### DB-RISK-007 (`leaves.status` ไม่รองรับ `Voided`) — **ปิดแล้ว**

```
leaves.status : varchar(20)  NOT NULL  DEFAULT 'Draft'
CHECK constraint: leaves_status_check
  status in ('Draft','Pending','Approved','Rejected','Cancelled','Voided')
```

เดิมเป็น `enum(...)` ที่ไม่มี `Voided` ทำให้ฟีเจอร์ void ใบลาพังทั้งฟีเจอร์
LEGACY-01 แก้ด้วย migration forward-only แล้ว

### ความเสี่ยงคงเหลือ (ไม่ใช่ FAIL แต่ควรบันทึก)

ยังมีคอลัมน์ที่เป็น MySQL `enum` อีก **13 คอลัมน์** ซึ่งมีลักษณะเดียวกับที่เคยทำให้เกิด DB-RISK-007
คือถ้าโค้ดเพิ่มค่าสถานะใหม่โดยไม่ `ALTER TABLE` จะเกิด error ทันที (sql_mode มี `STRICT_TRANS_TABLES`)

```
leave_adjustments.status            leaves.cancellation_status
leave_approvals.status              leaves.leave_period
leave_evidences.verification_status leaves.substitute_status
leave_policies.period_basis         work_calendar_exceptions.day_type
saraban_document_attachments.type   saraban_documents.creation_mode
saraban_documents.priority          saraban_documents.secrecy
saraban_documents.status
```

ที่ควรจับตาที่สุดคือ `leave_approvals.status` และ `saraban_documents.status` เพราะเป็น
สถานะ workflow ที่มีโอกาสเพิ่มค่าใหม่ตอนทำ LEGACY-05

## 6. Row count baseline — PASS

**55 ตาราง · รวม 302 แถว** (เก็บไฟล์เต็มไว้ที่ `docs/checkpoints/legacy-00/rowcount-baseline-2026-08-12T22.txt`)

| ตาราง | แถว | | ตาราง | แถว |
|---|---:|---|---|---:|
| `migrations` | 94 | | `permissions` | 15 |
| `leave_policies` | 49 | | `menus` | 15 |
| `role_has_permissions` | 27 | | `leave_types` | 12 |
| `leave_balances` | 11 | | `personal_data_access_logs` | 8 |
| `users` | 7 | | `model_has_roles` | 7 |
| `leave_approvals` | 6 | | `user_profiles` | 6 |
| `roles` | 6 | | `fiscal_years` | 5 |
| `leave_audit_logs` | 5 | | `leave_action_logs` | 4 |
| `personnel_types` | 4 | | `setting_audit_logs` | 4 |
| `approval_steps` | 3 | | `leaves` | 3 |
| `menu_permissions` | 3 | | `settings` | 3 |
| `departments` | 2 | | `attendances` | 1 |
| `leave_document_sequences` | 1 | | `personal_access_tokens` | 1 |

**ตารางว่าง 29 ตาราง** — ที่ควรสังเกต: `holidays`, `document_templates`,
`saraban_document_types` และตาราง Saraban ทั้งหมด (โมดูลยังไม่มีข้อมูลจริง)

### เทียบกับ baseline ครั้งก่อน (298 แถว)

| ตาราง | ก่อน | ตอนนี้ | สาเหตุ |
|---|---:|---:|---|
| `jobs` | 16 | **0** | LEGACY-01 สำรองแล้วล้างคิวที่ค้างจากการทดสอบ |
| `personal_access_tokens` | 9 | **1** | ผมเพิกถอนทั้ง 9 ตอน re-key · 1 ตัวที่เหลือคือ session ที่ login ใหม่หลังจากนั้น |
| `menus` | 0 | **15** | LEGACY-01 MenuSeeder |
| `menu_permissions` | 0 | **3** | LEGACY-01 |
| `fiscal_years` | 0 | **5** | GATE-02 |
| `personal_data_access_logs` | 6 | **8** | การเข้าถึงระหว่างตรวจสอบ |

### ตัวชี้วัดสุขภาพข้อมูล

| รายการ | ค่า |
|---|---|
| `leaves` ที่ถูก soft-delete | **3 / 3** (ทุกใบเป็นข้อมูลทดสอบที่ถูกลบแล้ว) |
| `leaves` สถานะ `Voided` | 0 |
| `jobs` ค้างในคิว | **0** |
| `failed_jobs` | **0** |
| `fiscal_years` ที่ `is_current` | **1 / 5** ✓ |

## 7–8. Backup — PASS

| ไฟล์ | ขนาด | เนื้อหา |
|---|---:|---|
| `legacy00-db-2026-08-12T22.sql` | 125,318 B | 55 `CREATE TABLE` · 26 `INSERT` · มี footer `Dump completed` (ไม่ถูกตัดกลางคัน) · ไม่มี BOM |
| `legacy00-storage-2026-08-12T22.tar.gz` | 1,334,875 B | 32 รายการจาก `storage/app` |
| `legacy00-SHA256SUMS.txt` | — | checksum ทั้งสองไฟล์ |

เก็บที่ `docs/checkpoints/legacy-00/backups/` ซึ่ง **อยู่ใน `.gitignore`** เพราะมีข้อมูลจริง
(password hash, เลขบัตรประชาชนที่เข้ารหัส, ลายเซ็น)

### ⚠️ ข้อบกพร่องที่เจอระหว่างทำ backup และแก้แล้ว

ครั้งแรก `mysqldump` จาก container `backend` ไปยัง host `db` **ล้มเหลว**:

```
mysqldump: Got error: 2026: "TLS/SSL error: self-signed certificate in certificate chain"
```

ได้ไฟล์ **0 ไบต์** และถ้าไม่ได้ตรวจ checksum จะเข้าใจผิดว่า backup สำเร็จ
แก้โดย dump จากใน container `db` ผ่าน socket แทน แล้วตรวจว่าไฟล์สมบูรณ์จริง

> **บทเรียนเชิงปฏิบัติการ:** สคริปต์ backup ใด ๆ ที่เชื่อมข้าม container ต้องระบุ `--ssl-mode`
> ให้ชัด และต้องตรวจขนาด/footer ของไฟล์เสมอ ไม่ใช่ดูแค่ exit code

### backup อัตโนมัติ (`spatie/laravel-backup`) — ตรวจแล้วว่าใช้ได้จริง

| รายการ | ผล |
|---|---|
| ตารางเวลา | `backup:run` ทุกวัน 02:00 (ยืนยันจาก `schedule:list` — ครบกำหนดถัดไปในอีก 3 ชม.) |
| archive ล่าสุด | `2026-08-12-22-06-39.zip` (17,696 B) |
| เนื้อหาข้างใน | `db-dumps/mysql-eleave.sql` — **123,405 B · 55 CREATE TABLE · 25 INSERT** ✓ |
| ขอบเขตที่ตั้งค่าไว้ | `storage_path('app')` + database (ยกเว้น `vendor`, `node_modules`, `storage/framework`) |
| ทำงานได้ด้วยสิทธิ์ `eleave_app` ที่จำกัดแล้ว | **ใช่** — รัน `backup:run --only-db` สำเร็จและ verify ผ่าน |

## 9. Restore drill — PASS

restore ลง container `mysql:8.0` ที่สร้างขึ้นใหม่แยกต่างหาก (**ไม่แตะ `eleave_db`**) แล้วลบทิ้งหลังตรวจเสร็จ

| การตรวจ | ผล |
|---|---|
| ตาราง | **55 / 55** |
| แถวรวม | **302 / 302** |
| ตารางที่จำนวนแถวไม่ตรง | **0** |

### ตรวจความถูกต้องของเนื้อหา ไม่ใช่แค่จำนวนแถว

| ค่าที่เทียบ | production | restored |
|---|---|---|
| MD5 ของ password hash ทุกคน | `e1f8a422…` | `e1f8a422…` ✓ |
| MD5 ของเลขบัตรที่เข้ารหัส | `09484048…` | `09484048…` ✓ |
| MD5 ของเลขที่หนังสือ (hex) | `80913ced…` | `80913ced…` ✓ |
| MD5 ของปีงบประมาณ | `dc25221c…` | `dc25221c…` ✓ |
| `leaves.status` ของ id=3 | `Cancelled` | `Cancelled` ✓ |

**ตรงกันทุกค่า** — ยืนยันว่ากู้คืนได้จริงทั้งข้อมูลภาษาไทยและ ciphertext

## 10. `php artisan test` — PASS

```
Tests:    130 passed (351 assertions)
Duration: 200.96s
```

> รันบน SQLite in-memory ตาม `phpunit.xml` ไม่ได้แตะฐานข้อมูลจริง
> CI ที่ LEGACY-01 เพิ่มไว้มี job รัน migration บน MySQL 8 แยกต่างหากเพื่อปิดช่องนี้ (DB-RISK-008)

## 11. `npm run build` — PASS

```
✓ 877 modules transformed
✓ built in 41.25s
```

`dist/index.html` ถูกสร้างใหม่จริง (timestamp ขยับจาก 21:08 เป็น 22:59) · 73 ไฟล์ใน `dist`

## 12. Queue / Worker / Scheduler — PASS

| รายการ | ผล |
|---|---|
| process `queue:work` ใน `eleave_worker` | **1** (ตรวจด้วย `docker top` — container ไม่มี `ps`) |
| process `schedule:work` ใน `eleave_scheduler` | **1** |
| งานค้างในคิว | 0 |
| `failed_jobs` | 0 |
| scheduler log | `Running scheduled tasks` เดินอยู่จริง |

### งานที่ตั้งเวลาไว้

```
0  2 * *  *    backup:run ................ อีก 3 ชั่วโมง
0  * * *  1-5  leave:governance .......... อีก 6 นาที
0  3 1 *  *    leave:governance --purge .. อีก 2 สัปดาห์
10 0 1 10 *    app:carry-forward-leaves .. อีก 1 เดือน
```

**นี่คือการเปลี่ยนแปลงที่ใหญ่ที่สุดจาก baseline เดิม** — ตอน LEGACY-00 ครั้งแรกไม่มี worker
และไม่มี scheduler เลย ทำให้มี job ค้าง 16 รายการที่ไม่เคยถูกส่ง และ `backup:run`
ที่ตั้งเวลาไว้ก็ล้มทุกคืนเพราะยังไม่ได้ติดตั้ง package

### บันทึกความโปร่งใส

ผม dispatch closure job เพื่อทดสอบว่า worker กินงานจริง ผลคือคิวลดจาก 1 → 0 (worker ทำงาน ✓)
แต่ job **fail** ด้วย:

```
Error: Call to a member function bindTo() on null
  in vendor/laravel/serializable-closure/src/Serializers/Native.php:200
```

เป็นข้อจำกัดของการส่ง closure จาก `tinker` ไปให้ worker process อื่นแกะ
**ไม่ใช่ข้อบกพร่องของ worker** — และการที่ job ถูกดึงออกจากคิวคือหลักฐานที่ต้องการอยู่แล้ว

ผมลบแถว `failed_jobs` ที่เกิดจาก probe ตัวเองทิ้ง เพื่อให้ baseline สะท้อนสภาพจริง
(`failed_jobs` = 0) ไม่ใช่ผลข้างเคียงจากการตรวจ

## 13. P0 Security findings — WARNING

ยิง HTTP จริงแบบไม่ส่ง token + ตรวจ middleware ที่บังคับใช้จริง + อ่านโค้ด controller

### ปิดแล้ว — ยืนยันด้วยหลักฐาน

| ID | เดิม | หลักฐานว่าปิดแล้ว |
|---|---|---|
| **SEC-020** `APP_KEY` รั่วผ่าน `phpinfo()` | 200 | `/info.php` → **404** · `app.previous_keys` = 0 (re-key เสร็จ) |
| **SEC-010** DB credential ใน `test_db.php` | 200 | `/test.php`, `/test_db.php` → **404** |
| **SEC-011** `/fix-leaves` เขียน DB ไม่ต้อง login | 200 | → **404** |
| **SEC-001** ไฟล์ส่วนบุคคลเปิดสาธารณะ | 200 ทั้ง 2 เส้นทาง | `/api/storage/{path}` → **404** · `/storage/{path}` → **403** · `/api/files/{path}` → **401** |
| **SEC-005** เอกสารลับสารบรรณเปิดให้ทุกคน | ไม่มี authz | middleware จริง: `auth:sanctum, permission:saraban.document.view, saraban.classified` ✓ |
| **SEC-006** ลบเอกสารสารบรรณไม่ต้องมีสิทธิ์ | ไม่มี authz | `permission:saraban.document.delete` ทั้ง `bulk` และรายฉบับ ✓ |
| **DB-RISK-007** `Voided` ใช้ไม่ได้ | enum | `varchar(20)` + CHECK ✓ |
| **DB-RISK-006** schema drift | ไม่ยืนยัน | migration 94 = ไฟล์ 94, pending 0 ✓ |
| **GATE-01** MySQL root ไม่มีรหัสผ่าน | root/ว่าง | แอปใช้ `eleave_app@%` สิทธิ์จำกัด · `root@%` ถูกถอด ✓ |

> SEC-005/006 อ้างอิงจาก middleware ที่ผูกกับ route จริง ประกอบกับ test ชุด
> `DocumentAuthorizationTest`, `ClassifiedDocumentTest`, `SignatureTest` ที่ผ่านใน 130 tests
> ผมไม่ได้สร้าง token ของผู้ใช้สิทธิ์ต่ำมาทดสอบซ้ำเอง

### 🔴 ยังเปิดอยู่ — 3 รายการ

| ID | สถานะ | หลักฐาน |
|---|---|---|
| **SEC-003** IDOR ดาวน์โหลดใบลาผู้อื่น | **เปิด** | `api/leaves/{leave}/pdf` และ `/word` middleware มีแค่ `api, auth:sanctum` · controller `exportPdf()` บรรทัด 639 และ `exportWord()` บรรทัด 766 เริ่มด้วย `$leave->load(...)` ทันที ไม่มี `authorize()` · **ไม่มี `LeavePolicy`** (`app/Policies/` มีแค่ `Saraban/DocumentPolicy.php`) |
| **SEC-004** `hr-verify` ไม่ตรวจสิทธิ์ | **เปิด** | middleware มีแค่ `auth:sanctum` · `hrVerify()` บรรทัด 515 เริ่มด้วย `$leave->hr_verified_by = ...` ทันที |
| **SEC-009** export ข้อมูลลงเวลาทั้งองค์กร | **เปิด** | 4 endpoint มี middleware **`[api]` เท่านั้น** ไม่มี `auth:sanctum` ไม่มี `can:` · controller ตรวจแค่ว่า token ใช้ได้ (`PersonalAccessToken::findToken(...)` บรรทัด 784, 812) **ไม่ตรวจ `attendance.view_all`** · token ยังส่งทาง query string (CORE-AUTH-002) |

**สำคัญ:** ทั้ง 3 รายการคืน **401 เมื่อยิงแบบไม่ส่ง token** ซึ่ง **ไม่ได้แปลว่าปิดแล้ว**
ช่องโหว่คือ *ผู้ใช้ที่ล็อกอินคนใดก็ได้* เข้าถึงข้อมูลของผู้อื่นได้ ไม่ใช่คนนอกระบบ

> **ข้อจำกัดของการพิสูจน์ SEC-003:** ใบลาทั้ง 3 ใบถูก soft-delete หมดแล้ว
> route model binding จึงคืน 404 ทำให้ **พิสูจน์ IDOR ด้วย HTTP ไม่ได้ในสภาพข้อมูลปัจจุบัน**
> ข้อสรุปข้างต้นมาจากการอ่านโค้ดและ middleware ไม่ใช่การ exploit จริง

ทั้งสามรายการอยู่ในขอบเขต **LEGACY-02 §02-B** ซึ่งยังไม่เริ่มตามคำสั่ง

### 🟠 ความเสี่ยงคงเหลืออื่น

| รายการ | สถานะ |
|---|---|
| `sanctum.expiration = NULL` (SEC-012) | token ไม่หมดอายุ — LEGACY-02 §02-C1 (ต้องได้คำตอบว่าจะตั้งกี่นาที) |
| `session.secure = NULL` | ไม่มี Secure cookie flag |
| `APP_DEBUG=true` บนระบบที่ออกอินเทอร์เน็ต | `ProductionEnvironmentGuard` ครอบเฉพาะ `APP_ENV=production` |
| `/api/files/{path}` ไม่มี ABAC รายไฟล์ | ผู้ใช้ที่ล็อกอินเปิดไฟล์ผู้อื่นได้ถ้ารู้ path (คอมเมนต์กำกับไว้ในคลาสแล้ว) |

### ⛔ อยู่นอกมือ — port forward

`eleave_backend` เปิด `0.0.0.0:8001` และ `eleave_frontend` เปิด `0.0.0.0:5173`
ส่วน `eleave_db` ผูก `127.0.0.1` แล้ว (ไม่ออกอินเทอร์เน็ต ✓)

การที่ระบบเข้าถึงได้จากอินเทอร์เน็ตเกิดจากการ forward port ที่ router ซึ่งอยู่นอกเครื่องนี้
**ผู้ดูแลระบบต้องเป็นผู้ดำเนินการ**

---

## Exit Criteria ของ LEGACY-00

| เกณฑ์ | ผล |
|---|:---:|
| มี backup ที่ **restore สำเร็จจริง** ไม่ใช่แค่มีไฟล์ | ✅ 55 ตาราง / 302 แถว / checksum เนื้อหาตรง |
| schema production ได้รับการยืนยันว่าตรงกับ migration | ✅ 94 = 94, pending 0, ghost 0 |
| มีผล test / build จริงบันทึกไว้ | ✅ 130 tests, build ผ่าน |
| มี git baseline ที่ย้อนกลับได้ | ✅ tag + tree สะอาด + ตรงกับ origin |

**ผ่านเกณฑ์ทางเทคนิคครบทุกข้อ**

---

## สรุป: **WARNING**

ไม่มีรายการใด FAIL · ระบบมี backup ที่กู้คืนได้จริง, schema ตรงกับ migration, test เขียว,
build ผ่าน, worker และ scheduler ทำงานจริง

ให้ WARNING เพราะ **P0 security 3 รายการยังเปิดอยู่** (SEC-003, SEC-004, SEC-009)
ซึ่งทั้งหมดเป็นงานของ LEGACY-02 ที่ยังไม่ได้รับอนุมัติให้เริ่ม บวกกับ `APP_DEBUG=true`
และ port forward ที่ยังเปิดอยู่

> ⚠️ ข้อสังเกตที่ควรพิจารณา: ระบบเข้าถึงได้จากอินเทอร์เน็ตและยังมี P0 เปิดอยู่ 3 รายการ
> ถึงแม้จะจำกัดอยู่ที่ "ผู้ใช้ที่มีบัญชีในระบบ" แล้วก็ตาม

---

## หยุดที่นี่

**ไม่เริ่ม LEGACY-01 และไม่เริ่ม LEGACY-02** — รอคำสั่ง

สิ่งที่ต้องตัดสินใจก่อนไปต่อ:

1. อนุมัติให้เริ่ม **LEGACY-02 Security Hardening** เพื่อปิด SEC-003 / SEC-004 / SEC-009 หรือไม่
2. `sanctum.expiration` ตั้งกี่นาที (ยอมให้ผู้ใช้ต้อง login ใหม่บ่อยขึ้นแค่ไหน)
3. ปิด port forward ที่ router — หรือให้ผม bind `127.0.0.1` ใน compose (จะตัดผู้ใช้ระยะไกลทั้งหมด)
4. ข้อมูลวันหยุดและแม่แบบเอกสารที่องค์กรรับรอง (GATE-02 ที่ยังค้าง)

---

## Sign-off

| บทบาท | ชื่อ | วันที่ | ผล |
|---|---|---|---|
| ผู้จัดทำ | AI Agent (Claude Code) | 2026-08-12 | WARNING |
| ผู้ตรวจทาน | | | ☐ อนุมัติ ☐ ขอแก้ไข |
| ผู้อนุมัติให้เริ่ม Phase ถัดไป | | | ☐ อนุมัติ ☐ ไม่อนุมัติ |
