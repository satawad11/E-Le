# LEGACY-00 — FREEZE & BASELINE REPORT

**Phase:** `LEGACY-00 Freeze & Baseline`
**วันที่ดำเนินการ:** 12 สิงหาคม 2569 (2026-08-12)
**ผู้ดำเนินการ:** AI Agent (Claude Code)
**อ้างอิงแผน:** `docs/LEGACY-MIGRATION-PLAN.md` §3 Phase LEGACY-00
**Architecture Decision ที่ได้รับอนุมัติ:** คงเทคโนโลยีเดิม — Laravel + Vue 3 + MySQL 8 + Tailwind + Docker
เอกสาร Target Architecture ตีความเป็น Architectural Principles และ Engineering Standards **ไม่ใช่คำสั่งให้ rewrite stack**

---

## สรุปผลรวม

| # | หัวข้อ | สถานะ |
|---|---|:---:|
| 1 | Git baseline | **PASS** |
| 2 | Database schema status | **PASS** |
| 3 | Schema drift | **PASS** |
| 4 | Database backup status | **PASS** |
| 5 | Restore test result | **PASS** |
| 6 | Storage backup status | **PASS** |
| 7 | Row count baseline | **PASS** |
| 8 | Test result | **PASS** |
| 9 | Frontend build result | **PASS** |
| 10 | Blocking issues | **WARNING** (ไม่มี FAIL) |
| 11 | Recommended actions for LEGACY-01 | — |

### **ไม่มีหัวข้อใดเป็น FAIL → LEGACY-01 เริ่มได้เมื่อได้รับอนุมัติ**

> มี WARNING 6 ข้อที่ต้องรับทราบก่อนอนุมัติ (§10) โดยเฉพาะ **W-1 (baseline ยังอยู่บนเครื่องเดียว)**
> และ **W-2 (ยังไม่มีระบบ backup อัตโนมัติ)**

### การปฏิบัติตามข้อกำหนด 8 ข้อ

| # | ข้อกำหนด | ปฏิบัติ |
|---|---|:---:|
| 1 | ห้ามแก้ Business Logic | ✅ ไม่แตะไฟล์ใด ๆ ใน `app/`, `routes/` |
| 2 | ห้ามแก้ Leave Policy | ✅ ไม่แตะ `leave_policies` ทั้ง schema และข้อมูล (49 แถวเท่าเดิม) |
| 3 | ห้ามเปลี่ยน Workflow | ✅ ไม่แตะ `approval_steps` (3 แถวเท่าเดิม) |
| 4 | ห้ามสร้าง Feature ใหม่ | ✅ ไม่มีโค้ดใหม่ |
| 5 | ห้าม Drop/Rename Database | ✅ ไม่มี DROP/RENAME ใด ๆ บน `eleave` |
| 6 | ห้ามรัน migration down | ✅ ไม่ได้รัน `migrate:rollback`/`migrate:fresh`/`migrate:reset` |
| 7 | ห้ามลบข้อมูลจริง | ✅ row count ก่อน = หลัง = 298 แถว |
| 8 | ห้ามแก้ production schema ก่อนมี backup ที่ restore ได้จริง | ✅ **ไม่แก้ production schema เลย** |

**การเขียนที่เกิดขึ้นทั้งหมดในเฟสนี้:** ไฟล์เอกสารใน `docs/checkpoints/`, `.gitignore` ที่ root, `TASKS.md`,
git commit + tag · **ไม่มีการเขียนลงฐานข้อมูล production แม้แต่คำสั่งเดียว**

---

## 1. Git baseline — **PASS**

| รายการ | ค่า |
|---|---|
| Branch | `main` |
| Commit | `61392121bfdcb4ddc06da396d6f8b909aa7f7e5a` (`6139212`) |
| วันที่ | 2026-08-12 03:03:33 +0700 |
| Tag (annotated) | `baseline/legacy-2026-08-12` |
| ไฟล์ที่ commit | **258 ไฟล์** (+15,761 / −3,058 บรรทัด) |
| working tree หลัง commit | **clean** |
| commit ก่อนหน้า | `20abc58` "update" (2026-07-18) |

### สิ่งที่อยู่ใน baseline commit

- งานที่ค้างไม่ได้ commit 73 modified / 4 deleted — โมดูล Leave ราชการ, Saraban, PDPA layer, government reports
- ชุดเอกสาร E-la ทั้งหมด (`docs/`, `AGENTS.md`, `PROJECT_RULES.md`, `CURRENT_PHASE.md`, `START-HERE.md`, `TASKS.md`)
- ผล Legacy Assessment / Gap Analysis / Migration Plan
- `.gitignore` ที่ root (ดู §10 W-6)

### การตรวจก่อน commit

| ตรวจ | ผล |
|---|---|
| `backend/.env` ติดเข้าไปหรือไม่ | ❌ ไม่ (git-ignored ถูกต้อง) |
| `vendor/`, `node_modules/` | ❌ ไม่ |
| ลายเซ็น / ภาพลงเวลา / ใบรับรองแพทย์ใน `storage/app` | ❌ ไม่ — `storage/app/.gitignore` ครอบคลุมถูกต้อง มีเพียง `.gitignore` 3 ไฟล์ที่ถูก track |
| `.env.testing` (tracked) มี secret จริงหรือไม่ | ❌ ไม่ — `APP_KEY` เป็นค่า dummy `base64:AAAA...` |
| ไฟล์ใหญ่ผิดปกติ | ❌ ไม่ — ใหญ่สุด 151 kB (ภาพประกอบเอกสาร audit) |

> **หมายเหตุ:** baseline commit นี้เก็บสภาพเดิม**ตามจริง** รวมไฟล์ที่รู้อยู่แล้วว่าต้องลบ
> (`public/info.php`, `public/test_db.php`, `backend/eleave`, `sync_test_accounts.php` ฯลฯ)
> เพื่อให้เป็นจุดย้อนกลับที่ตรงกับความเป็นจริง — ไฟล์เหล่านี้จะถูกลบใน LEGACY-01

---

## 2. Database schema status — **PASS**

| รายการ | ค่า |
|---|---|
| MySQL server | 8.0.46 (container `eleave_db`, healthy) |
| Database | `eleave` |
| จำนวนตาราง | **55** |
| Engine / Collation | InnoDB / `utf8mb4_unicode_ci` ทุกตาราง |
| `sql_mode` | `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` |
| `php artisan migrate:status` | **91 migration — `Ran` ทั้งหมด, `Pending` = 0** |

รายละเอียดเต็มใน `docs/checkpoints/legacy-00/schema-baseline.md`

---

## 3. Schema drift — **PASS (ไม่พบ drift)**

### วิธีตรวจ

สร้าง MySQL 8.0.46 container ใหม่แยกต่างหาก (`eleave_legacy00_verify`) → สร้าง database เปล่า
`expected_schema` → รัน `php artisan migrate --force` จากศูนย์ (**สำเร็จครบ 91 migration, exit 0**)
→ เทียบกับ schema จริงด้วย 3 วิธีอิสระ

### ผล

| วิธี | ผล |
|---|:---:|
| จำนวนตาราง | 55 = 55 ✅ |
| `diff` ของ DDL dump (normalize `AUTO_INCREMENT` และชื่อ DB) | **0 บรรทัดต่าง** ✅ |
| MD5 ของทุกคอลัมน์ (name, type, nullable, default, extra) | `a498622d…c5319` ตรงกันทั้งสองฝั่ง ✅ |
| MD5 ของทุก index | `531f7c3c…200d9` ตรงกันทั้งสองฝั่ง ✅ |
| MD5 ของทุก foreign key | ตรงกัน ✅ |

### 🔄 แก้ข้อสรุปเดิม — DB-RISK-006 ถูกหักล้าง

`LEGACY-SYSTEM-ASSESSMENT` จัดให้ "schema จริงอาจไม่ตรงกับ migration" เป็น **P0 อันดับ 1** และเป็นตัวบล็อกทุกงาน
โดยอนุมานจาก `if (Schema::hasTable('personnel_types')) return;` และ migration ชื่อ `resume_interrupted_leave_hardening`

**ผลตรวจจริงพิสูจน์ว่าไม่มี drift** — migration กู้สถานการณ์ทำงานถูกต้องสมบูรณ์
**DB-RISK-006 → RESOLVED** และ Top-10 gap อันดับ 1 ถูกปิด

### ⚠️ แต่พบ schema-related defect จริงอีกเรื่อง (คนละเรื่องกับ drift)

**`leaves.status` ไม่มีค่า `'Voided'`** — ทั้งใน schema จริง **และ** ใน schema ที่ migration สร้าง
(จึงยืนยันว่า **ไม่ใช่ drift** แต่เป็น **code ↔ schema contract mismatch**)

โค้ด `LeaveGovernanceController::void()` สั่ง `update(['status' => 'Voided'])`

**ทดลองจริงบนสำเนาที่ restore แล้วใน container แยก (ไม่แตะ production):**

```sql
UPDATE eleave.leaves SET status='Voided' WHERE id=1;
-- ERROR 1265 (01000): Data truncated for column 'status' at row 1
```

| ประเด็น | ผล |
|---|---|
| ฟีเจอร์ "ยกเลิกเอกสารโดยคงประวัติ (void)" | **ใช้งานไม่ได้เลย → HTTP 500 ทุกครั้ง** |
| ความเสี่ยงข้อมูลเสียหาย | **ไม่มี** — `STRICT_TRANS_TABLES` + `DB::transaction` ทำให้ rollback ทั้งก้อน (fail-safe) |
| ข้อมูลที่ได้รับผลกระทบ | `status='Voided'` = 0 แถว, `voided_at IS NOT NULL` = 0 แถว |
| ปรับระดับ DB-RISK-007 | จาก **CRITICAL / data loss** → **HIGH / ฟีเจอร์เสีย ล้มอย่างปลอดภัย** |

**ตามข้อกำหนด "หากพบ schema drift ห้ามแก้ทันที" — บันทึก expected / actual / difference / risk /
recommended repair ไว้ครบใน `docs/checkpoints/legacy-00/schema-baseline.md` §5 แล้ว ยังไม่ได้แก้ไขใด ๆ**

---

## 4. Database backup status — **PASS**

| รายการ | ค่า |
|---|---|
| ไฟล์ | `docs/checkpoints/legacy-00/backups/eleave-full-2026-08-12.sql` |
| ขนาด | 143,497 bytes |
| คำสั่ง | `mysqldump --single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4 --databases eleave` |
| CREATE TABLE ในไฟล์ | 55 |
| INSERT statements | 24 |
| ตรวจว่าไฟล์ไม่ถูกตัด | บรรทัดสุดท้าย = `-- Dump completed on 2026-08-11 20:07:07` ✅ |
| Encoding | ไม่มี BOM (dump ภายใน container แล้ว `docker cp` ออกมาเพื่อความถูกต้องระดับไบต์) ✅ |
| SHA-256 | `66768016adb25feda3dac96bdb6d92fa07ed6d678409a64285da16ca63862953` |

> **ข้อควรทราบ:** ครั้งแรกที่ dump ผ่าน PowerShell redirect ได้ไฟล์ที่มี UTF-8 BOM นำหน้า
> ซึ่งจะทำให้ `mysql < file` import ไม่ผ่าน — ตรวจพบและ dump ใหม่ให้ถูกต้อง
> **บทเรียนสำหรับ runbook backup ในอนาคต: ต้องตรวจ BOM ทุกครั้งบน Windows**

---

## 5. Restore test result — **PASS**

ทดสอบบน **instance แยกจริง**: container `eleave_legacy00_verify` (mysql:8.0.46, network เดียวกัน, storage แยก)
— **ไม่แตะ `eleave_db` แม้แต่คำสั่งเดียว**

| ตรวจ | ผล |
|---|:---:|
| Import สำเร็จ | ✅ `IMPORT_OK` |
| จำนวนตารางหลัง restore | 55 = 55 ✅ |
| **Row count ครบทุกตาราง** | **55/55 ตาราง ตรงกัน · 298 = 298 แถว · mismatch = 0** ✅ |
| อักขระไทย (`HEX(document_number)`) | ตรงกันทุกไบต์ ✅ |
| ฟิลด์เข้ารหัส `user_profiles.id_card_number` (MD5 รวม) | `4b87b331161c374e79e5305ee968fb3c` ตรงกัน ✅ |
| Password hash ทุกผู้ใช้ (MD5 รวม) | `e1f8a42235ba1818b2cbf9fdac4c7df4` ตรงกัน ✅ |
| `leaves.content_hash` (ลายนิ้วมือใบลา) | `7095d6edc37ac5542d79a6d2ee5af022` ตรงกัน ✅ |

**ผลลัพธ์: backup นี้กู้คืนได้จริง และข้อมูลตรงกันทุกไบต์รวมถึงข้อมูลที่เข้ารหัสไว้**

รายละเอียดต่อตารางใน `docs/checkpoints/legacy-00/restore-verification.txt`

Container ทดสอบถูกลบทิ้งหลังเสร็จ · ยืนยันแล้วว่า production ยังทำงานปกติ (55 ตาราง / 3 ใบลา / 7 ผู้ใช้)

---

## 6. Storage backup status — **PASS**

| รายการ | ค่า |
|---|---|
| ไฟล์ | `docs/checkpoints/legacy-00/backups/storage-app-2026-08-12.tar.gz` |
| ขนาด | 39,185 bytes |
| จำนวน entry ใน tar | 21 (ไฟล์จริง 15 · ไฟล์เนื้อหา 12 ไม่นับ `.gitignore`) |
| SHA-256 ของ archive | `a18943d3a61f07a5db876ad4906175c192136ed8a86f6ea59d7a89ad50a18a02` |
| **ทดสอบแตกไฟล์ + เทียบ checksum รายไฟล์** | **12/12 ไฟล์ SHA-256 ตรงกันทั้งหมด** ✅ |

### เนื้อหาที่สำรอง

| ที่เก็บ | ไฟล์ |
|---|---|
| `storage/app/private/private/leave-evidences/` | 1 (ใบรับรอง PDF) |
| `storage/app/public/signatures/` | 11 (ลายเซ็นบุคลากร) |

Checksum รายไฟล์ทั้งหมดบันทึกใน `docs/checkpoints/legacy-00/backups/SHA256SUMS.txt`
รายการใน archive อยู่ที่ `storage-app-manifest.txt`

> ⚠️ ลายเซ็น 11 ไฟล์นี้อยู่บน disk `public` และเข้าถึงได้จากอินเทอร์เน็ตโดยไม่ต้อง login (SEC-001)
> — จะย้ายไป private ใน LEGACY-02

---

## 7. Row count baseline — **PASS**

**รวม 298 แถว ใน 55 ตาราง** (นับด้วย `COUNT(*)` จริง ไม่ใช่ค่าประมาณ)

| ตาราง | แถว | | ตาราง | แถว |
|---|---:|---|---|---:|
| `migrations` | 91 | | `leave_audit_logs` | 5 |
| `leave_policies` | 49 | | `leave_action_logs` | 4 |
| `role_has_permissions` | 27 | | `personnel_types` | 4 |
| `jobs` | **16** ⚠️ | | `setting_audit_logs` | 4 |
| `permissions` | 15 | | `approval_steps` | 3 |
| `leave_types` | 12 | | `leaves` | **3** ⚠️ |
| `leave_balances` | 11 | | `settings` | 3 |
| `personal_access_tokens` | 9 | | `departments` | 2 |
| `users` | 7 | | `attendances` | 1 |
| `model_has_roles` | 7 | | `leave_document_sequences` | 1 |
| `leave_approvals` | 6 | | *(อีก 31 ตาราง)* | 0 |
| `user_profiles` | 6 | | | |
| `roles` | 6 | | | |
| `personal_data_access_logs` | 6 | | | |

### 🔑 ข้อค้นพบที่เปลี่ยนการประเมินความเสี่ยงทั้งหมด

**ระบบนี้ยังไม่ได้ใช้งานจริง** — 7 ผู้ใช้ทดสอบ, 3 ใบลา (ถูก soft-delete ทั้งหมด), 1 รายการลงเวลา,
**0 หนังสือราชการ**, 0 ปีงบประมาณ, 0 วันหยุด, 0 เมนู

> **ตอบ Open Question #3 และ #5 ของ Assessment ได้แล้ว:**
> ระบบเป็น **development / pilot** ไม่ใช่ production ที่มีข้อมูลบุคลากรจริง
> และโมดูลสารบรรณ**ยังไม่เปิดใช้ ยังไม่มีเอกสารลับในระบบ**
>
> ผลคือ: ความเสี่ยงข้อมูลสูญหายจากการ migrate **ต่ำกว่าที่ประเมินไว้มาก** และ
> **ตอนนี้คือช่วงเวลาที่ดีที่สุดในการ refactor** — ต้นทุนของความผิดพลาดต่ำที่สุดเท่าที่จะเป็นไปได้
>
> **ข้อเสนอเชิงนโยบาย: ควรแก้ P0 ทั้งหมดให้เสร็จก่อนนำเข้าข้อมูลบุคลากรจริง**

รายละเอียดเต็มใน `docs/checkpoints/legacy-00/rowcount-baseline.md`

---

## 8. Test result — **PASS**

```
docker exec -w /var/www/html eleave_backend php artisan test
```

### **86 passed · 0 failed · 0 skipped · 256 assertions · 173.25s**

รันบน SQLite `:memory:` ตาม `phpunit.xml` — **ไม่แตะ MySQL production**

### 🔄 แก้ข้อสรุปเดิม — TEST-002 ถูกหักล้าง

Assessment §13 และ Gap Analysis TEST-002 ระบุว่าพบ defect 42 รายการใน `.phpunit.result.cache`
และคาดว่า "ประมาณครึ่งหนึ่งของ suite ไม่ผ่าน" **การรันจริงพิสูจน์ว่าไม่จริง — ผ่านทั้งหมด 86/86**

ไฟล์ `.phpunit.result.cache` เป็นแคชเก่าตกค้างจากสภาพแวดล้อมอื่น (PHP บน host เป็น 8.2 ซึ่งต่ำกว่า
ที่ Laravel 13 ต้องการ) และการตีความรหัสสถานะ 7/8 ว่าเป็น failure นั้นไม่ถูกต้อง

**ผลต่อแผน:** งาน `01-5 ทำ test suite ให้เขียว` **ตัดออกได้** — เปลี่ยนเป็น "ใส่ suite ที่เขียวอยู่แล้วเข้า CI"

### ช่องว่างของ test ที่ยังเป็นจริง (สำคัญกว่าจำนวน test)

| ช่องว่าง | สถานะ |
|---|---|
| IDOR test | **0** — จึงเป็นเหตุผลว่าทำไม SEC-003/005/006 ลอดผ่านมาได้ทั้งที่ test เขียว 100% |
| Authorization matrix (role × scope × action) | ไม่มี |
| Attendance / OT | **0 test** สำหรับ controller 1,137 บรรทัด |
| Saraban | 1 test สำหรับ 9 controller |
| `LeaveGovernanceController::void()` | **ไม่มี test** — จึงไม่มีใครจับได้ว่าฟีเจอร์นี้พังมาตลอด |

> ปริมาณ test ไม่ใช่ปัญหา **ขอบเขต** คือปัญหา

---

## 9. Frontend build result — **PASS**

```
docker exec -w /app eleave_frontend npm run build
```

| รายการ | ผล |
|---|---|
| Node / npm | v22.23.2 / 10.9.8 |
| **Exit code** | **0** ✅ |
| Error / Warning | ไม่มี |
| Output | ~60 chunk แบบ code-split ตาม route |
| Bundle ใหญ่สุด | `dist-*.js` 172 kB (gzip 59.5 kB), `quill` 164 kB, `index` 131 kB, `axios` 105 kB |

**ข้อสังเกต (ไม่กระทบสถานะ):** build ใช้เวลานานมาก (80% ของเวลาอยู่ใน plugin `vite:vue`) สาเหตุคือ
bind mount ของ Docker Desktop บน Windows ช้า — ไม่ใช่ปัญหาของโค้ด แต่จะกระทบเวลา CI ในอนาคต

---

## 10. Blocking issues — **WARNING (ไม่มี FAIL)**

### ไม่มีประเด็นใดถึงระดับ FAIL — LEGACY-01 เริ่มได้เมื่อได้รับอนุมัติ

| ID | ระดับ | ประเด็น | ทำไมยังไม่ใช่ FAIL | ต้องจัดการเมื่อไร |
|---|:---:|---|---|---|
| **W-1** | **WARNING** | **Baseline commit + tag อยู่บนเครื่องนี้เครื่องเดียว ยังไม่ได้ push ขึ้น `origin`** — จุดย้อนกลับที่อยู่บนเครื่องเดียวไม่ใช่จุดย้อนกลับที่แท้จริง | จุดย้อนกลับมีอยู่จริงและใช้ได้ในเครื่องนี้ | **ก่อนเริ่ม LEGACY-01** — ต้องการคำสั่งจากผู้ใช้เพื่อ push (เป็นการกระทำที่ส่งข้อมูลออกภายนอก จึงไม่ทำเอง) |
| **W-2** | **WARNING** | **ยังไม่มีระบบ backup อัตโนมัติ** — `routes/console.php` เรียก `Schedule::command('backup:run')` แต่ `spatie/laravel-backup` ไม่ได้ติดตั้งใน `composer.json` และไม่มี scheduler container | LEGACY-00 มี backup แบบ point-in-time ที่**พิสูจน์แล้วว่า restore ได้จริง** ซึ่งเพียงพอสำหรับงาน LEGACY-01 ที่ย้อนกลับได้ด้วย git | **งานแรกของ LEGACY-01** — ไม่ได้ทำในเฟสนี้เพราะการติดตั้ง package + แก้ `docker-compose.yml` อยู่นอกรายการงานที่ผู้ใช้อนุมัติสำหรับ LEGACY-00 |
| **W-3** | **WARNING** | **ฟีเจอร์ void ใบลาพังอยู่** (`leaves.status` ไม่มีค่า `Voided` → ERROR 1265) | ล้มอย่างปลอดภัย ไม่มีข้อมูลเสียหาย และไม่มีใครเคยใช้สำเร็จ (0 แถว) | LEGACY-01 หรือ LEGACY-03 (ถ้าเลือกแปลง enum → varchar) — **ต้องมี test ก่อนแก้** |
| **W-4** | **WARNING** | **งานแจ้งเตือนค้างในคิว 16 รายการ** (`LeaveRequested`, เก่าสุด 2026-08-09) ไม่เคยถูกส่ง เพราะไม่มี queue worker | เป็นการแจ้งเตือนของข้อมูลทดสอบ ไม่มีผลต่อข้อมูลจริง | **ต้องตัดสินใจก่อนเพิ่ม worker**: เปิด worker แล้วงานทั้ง 16 จะถูกส่งย้อนหลังทันที — ต้องการคำสั่งว่าจะส่งหรือล้างทิ้ง |
| **W-5** | **WARNING** | **ช่องโหว่ CRITICAL 6 + HIGH 6 ยังเปิดอยู่ทั้งหมด** (SEC-001 ถึง SEC-012) | เป็นขอบเขตของ LEGACY-02 ตามแผนที่อนุมัติแล้ว และข้อมูลที่รั่วได้จริงตอนนี้มีเพียง 11 ลายเซ็น + 1 ภาพ + 1 ใบรับรองของผู้ใช้ทดสอบ | LEGACY-02 · **แต่ถ้าระบบเข้าถึงได้จากอินเทอร์เน็ต ควรปิด route `/api/storage/{path}` ทันทีแยกต่างหาก** |
| **W-6** | **WARNING** | **ส่วนเบี่ยงเบนจากแผน 1 รายการ:** สร้าง `.gitignore` ที่ root (เพิ่ม `.pnpm-store/`, `node_modules/`, `.DS_Store`, `Thumbs.db`) | แผนกำหนดให้ทำใน LEGACY-01 แต่จำเป็นต้องทำก่อน commit มิฉะนั้น `.pnpm-store/` ซึ่งมีสำเนาซอร์ส frontend ทั้งชุดจะเข้าไปอยู่ใน baseline | รับทราบแล้ว — ไม่ต้องดำเนินการเพิ่ม |

### ความไม่สอดคล้องเชิงข้อมูลที่บันทึกไว้ (ยังไม่แก้ ไม่บล็อก)

| ประเด็น | หลักฐาน |
|---|---|
| `fiscal_years` = 0 แถว ทั้งที่ `LeavePolicyService` / `LeaveController::balances` เรียก `FiscalYear::current()` | ยอดวันลาอาจอิงปีที่ fallback ไม่ตรงกับที่ตั้งใจ |
| `menus` = 0 แถว ทั้งที่ migration เพิ่มเมนู governance รันแล้ว | sidebar ที่ generate จาก `menus` อาจว่าง |
| `document_templates` = 0 แถว | การพิมพ์แบบฟอร์ม fallback ไป blade view |
| `saraban_document_types` = 0 แถว | โมดูลสารบรรณสร้างหนังสือไม่ได้ในสภาพปัจจุบัน |
| `users` 7 คน แต่ `user_profiles` 6 | มีผู้ใช้ 1 คนที่ `$user->profile` เป็น `null` — โค้ดหลายจุดเรียก `$user->profile->department_id` โดยไม่ป้องกัน null |
| `App\Models\LeaveRequest` เป็น orphan model | ไม่มีตาราง `leave_requests` ในฐานข้อมูล |
| ตารางที่ Assessment รอบแรกตกหล่น | `menu_permissions`, `setting_audit_logs`, `cache_locks` · และ **ไม่มี** ตาราง `leave_eligibility_rules` (กฎเก็บเป็น JSON ใน `leave_policies.eligibility_rules`) |

---

## 11. Recommended actions for LEGACY-01

### ก่อนเริ่ม (ต้องการการตัดสินใจจากผู้ใช้)

| # | เรื่อง | คำถาม |
|---|---|---|
| D-1 | **Push baseline** | อนุญาตให้ `git push origin main --follow-tags` เพื่อให้จุดย้อนกลับอยู่บน remote ด้วยหรือไม่? |
| D-2 | **งานค้างในคิว 16 รายการ** | เมื่อเปิด queue worker งานเหล่านี้จะถูกส่งย้อนหลังทันที — ต้องการให้ **ส่ง** หรือ **ล้างทิ้ง** ก่อนเปิด worker? |
| D-3 | **วิธีแก้ `leaves.status`** | เลือกทางไหน: (A) แปลง `enum` → `varchar` + CHECK *(แนะนำ — แก้ปัญหาการเพิ่มสถานะในอนาคตด้วย)* · (B) `ALTER` เพิ่มค่า `Voided` เข้า enum · (C) ไม่แตะ schema แล้วแก้โค้ดให้ใช้ `Cancelled` + `voided_at` แทน |
| D-4 | **ระดับความเร่งด่วนของ SEC-001** | ระบบเข้าถึงได้จากอินเทอร์เน็ตหรืออยู่ในวงแลนภายในเท่านั้น? ถ้าเข้าถึงได้จากภายนอก ควรปิด route `/api/storage/{path}` แยกทันทีก่อนงานอื่น |

### แผนงาน LEGACY-01 ฉบับปรับปรุงตามผล LEGACY-00

| # | งาน | เปลี่ยนแปลงจากแผนเดิม |
|---|---|---|
| 01-0 | **ติดตั้ง backup อัตโนมัติ** — `composer require spatie/laravel-backup` + config + เพิ่ม service `scheduler` (`schedule:work`) และ `worker` (`queue:work`) ใน `docker-compose.yml` + ตั้ง off-site + ซ้อม restore | **เลื่อนมาจาก 00-4 · เป็นงานอันดับแรก** (แก้ W-2, W-4, DEPLOY-003, NOTIFY-004 พร้อมกัน) |
| 01-1 | ลบ `backend/public/info.php`, `test.php`, `test_db.php` | ตามเดิม (SEC-010) |
| 01-2 | ลบ route `GET /fix-leaves` ใน `routes/web.php` | ตามเดิม (SEC-011) |
| 01-3 | หมุน DB credentials + ตั้งรหัส MySQL + เลิกใช้ root | ตามเดิม (SEC-015) — **ทำได้ต้นทุนต่ำมากตอนนี้เพราะข้อมูลยังน้อย** |
| 01-4 | ลบไฟล์ dev ออกจาก repo: `debug.php`, `fix.php`, `fix_hash.php`, `sqlite_test.php`, `seed_balances.php`, `sync_test_accounts.php`, `test_api_call.php`, `test_login.php`, `backend/eleave`, `.phpunit.result.cache` | เพิ่ม `.phpunit.result.cache` เข้ารายการ (เป็นต้นเหตุของการวินิจฉัยผิดในรอบแรก) · `.gitignore` ที่ root ทำไปแล้ว |
| ~~01-5~~ | ~~ทำ test suite ให้เขียว~~ | **ตัดออก — test เขียว 86/86 อยู่แล้ว** |
| 01-6 | เพิ่ม CI: `pint` + `php artisan test` + **migration test บน MySQL 8** + `npm run build` | ตามเดิม · LEGACY-00 พิสูจน์แล้วว่า migrate จากศูนย์บน MySQL 8 สำเร็จ ให้ CI รักษาสถานะนี้ไว้ |
| 01-7 | Env validation ตอน boot (กัน `APP_DEBUG=true` บน production) | ตามเดิม (SEC-017) |
| 01-8 | Structured logging + correlation ID | ตามเดิม (OBS-001) |
| 01-9 | แก้ `LeaveController::update/destroy` ที่ใช้ `$user->role->name` (crash เมื่อ `role_id` เป็น null) | ตามเดิม (LEAVE-006) |
| **01-10** | **แก้ `leaves.status` ตามทางเลือกที่ผู้ใช้เลือกใน D-3 + เขียน test ครอบ `void()`** | **งานใหม่จาก LEGACY-00 (W-3)** |
| **01-11** | **Seed ข้อมูลตั้งต้นที่ขาด:** `fiscal_years`, `holidays`, `menus`, `document_templates`, `saraban_document_types` | **งานใหม่จาก LEGACY-00** — ระบบใช้งานไม่ได้ครบถ้วนถ้าไม่มี |

### ปรับปรุงเอกสารประเมิน (ทำไปแล้วในเฟสนี้)

| เอกสาร | การแก้ |
|---|---|
| `LEGACY-SYSTEM-ASSESSMENT.md` | DB-RISK-006 → RESOLVED · DB-RISK-007 ปรับระดับ + ยืนยันด้วยการทดลอง · §13 แก้ผล test เป็น 86/86 · §18 ตอบ Open Question #3, #4, #5 |
| `LEGACY-GAP-ANALYSIS.md` | TEST-002 → PASS · CORE-DB-001 → PASS · ปรับ Top 10 |
| `KNOWN-LIMITATIONS.md` | เพิ่ม known issues ที่พบจริงจาก LEGACY-00 |
| `TASKS.md` | ปิดงาน LEGACY-ASSESSMENT ที่ค้าง + เปิดรายการ LEGACY-00 |

---

## Exit Criteria ของ LEGACY-00

| เกณฑ์ | ผล |
|---|:---:|
| มี backup ที่ **restore สำเร็จแล้วจริง** (ไม่ใช่แค่มีไฟล์) | ✅ ผ่าน — restore บน instance แยก, 298/298 แถว, checksum ตรงทุกฟิลด์ |
| schema production ได้รับการยืนยันว่าตรง/ไม่ตรงกับ migration พร้อมรายการความต่างที่ชัดเจน | ✅ ผ่าน — ยืนยันด้วย 3 วิธีอิสระว่า **ตรงกัน 100%** |
| มีผล test/build จริงบันทึกไว้เป็น baseline | ✅ ผ่าน — 86/86 test, build exit 0 |
| มี git tag ที่ย้อนกลับได้ | ⚠️ ผ่านบางส่วน — มี tag แล้วแต่ยังอยู่เครื่องเดียว (W-1) |

---

## Sign-off

| บทบาท | ชื่อ | วันที่ | ผล |
|---|---|---|---|
| ผู้ดำเนินการ | AI Agent (Claude Code) | 2026-08-12 | LEGACY-00 เสร็จสมบูรณ์ · ไม่มี FAIL |
| ผู้ตรวจทาน | | | ☐ อนุมัติ ☐ ขอแก้ไข |
| ผู้อนุมัติให้เริ่ม LEGACY-01 | | | ☐ อนุมัติ ☐ ไม่อนุมัติ |

> **สถานะปัจจุบัน: หยุดรอคำสั่ง — ยังไม่เริ่ม LEGACY-01**
