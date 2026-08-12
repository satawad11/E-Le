# SCHEMA BASELINE — LEGACY-00

**วันที่:** 2026-08-12 · **Baseline tag:** `baseline/legacy-2026-08-12` (`6139212`)

## 1. สภาพแวดล้อมที่ตรวจ

| รายการ | ค่า |
|---|---|
| MySQL server (production) | 8.0.46 — container `eleave_db` |
| `sql_mode` | `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` |
| Database | `eleave` |
| Engine / Collation | InnoDB / `utf8mb4_unicode_ci` (ทุกตาราง) |
| PHP (backend container) | 8.4.24 |
| Laravel | 13.19.0 |
| Node / npm (frontend container) | v22.23.2 / 10.9.8 |

## 2. Migration status

`php artisan migrate:status` — **91 migration, สถานะ `Ran` ทั้งหมด, ไม่มี `Pending`**

Batch distribution: batch 1 = 78 รายการ, batch 2–12 = 13 รายการ (งาน hardening ช่วง 2026-08-09 ถึง 2026-08-10)

## 3. วิธีตรวจ schema drift

สร้าง schema "ที่ควรจะเป็น" ขึ้นมาใหม่จากศูนย์ แล้วเทียบกับ schema จริง:

1. สร้าง container ใหม่ `eleave_legacy00_verify` (mysql:8.0.46 เดียวกัน) แยกจาก production
2. สร้าง database เปล่า `expected_schema`
3. รัน `php artisan migrate --force` จาก backend container ชี้ไปที่ container ใหม่ → **สำเร็จครบ 91 migration (exit 0)**
4. `mysqldump --no-data` ทั้งสองฝั่ง แล้ว normalize (`AUTO_INCREMENT=n`, ชื่อ database) แล้ว `diff`
5. ตรวจซ้ำอีก 2 วิธีอิสระผ่าน `information_schema`

## 4. ผลการเปรียบเทียบ

| วิธีตรวจ | ผลลัพธ์ |
|---|---|
| จำนวนตาราง | 55 (actual) = 55 (expected) ✅ |
| `diff` ของ DDL dump (normalize แล้ว) | **0 บรรทัดต่าง** ✅ |
| MD5 ของ `information_schema.columns` (table, column, type, nullable, default, extra) | `a498622d5fd0d6e7cb648df3710c5319` ทั้งสองฝั่ง ✅ |
| MD5 ของ `information_schema.statistics` (index ทุกตัว) | `531f7c3cc5c2ab4f5f4d31e9d2e200d9` ทั้งสองฝั่ง ✅ |
| MD5 ของ foreign keys (`key_column_usage`) | ตรงกัน ✅ |

### สรุป: **SCHEMA DRIFT = 0**

> **แก้ข้อสรุปเดิม:** `docs/LEGACY-SYSTEM-ASSESSMENT.md` DB-RISK-006 ตั้งสมมติฐานว่า schema production อาจไม่ตรงกับ migration
> เพราะพบ `if (Schema::hasTable('personnel_types')) return;` ใน `2026_08_08_100000` และ migration
> `2026_08_08_100050_resume_interrupted_leave_hardening` — **ผลตรวจจริงหักล้างสมมติฐานนี้**
> migration กู้สถานการณ์ทำงานถูกต้องครบถ้วน schema ตรงกันทุกคอลัมน์ ทุก index ทุก FK
> **DB-RISK-006 → ปิดได้ (RESOLVED — ไม่พบ drift)**

### ข้อสังเกตเพิ่มเติมจากการรัน migration ใหม่

DDL บนตารางที่ผ่านการแก้ซ้ำ ๆ (`leaves`, `leave_approvals`, `leave_balances`) **ช้ามาก** — แต่ละ `ALTER TABLE`
ใช้เวลา 15–60 วินาทีบน container ที่เพิ่ง initialize รวมทั้งชุดใช้เวลาประมาณ 12 นาที
ตรวจ `information_schema.processlist` ระหว่างรันพบสถานะ `preparing for alter table` และ
`performance_schema.data_lock_waits = 0` → **ไม่ใช่การ deadlock แต่ช้าจาก I/O จริง**

นี่อธิบายเหตุการณ์ DDL timeout ในอดีตได้ และเป็นเหตุผลว่าทำไมทุก `ALTER TABLE` ในเฟสถัดไป
ต้องประเมินเวลา/lock ล่วงหน้าและทำนอกเวลาราชการ (ตาม `LEGACY-MIGRATION-PLAN` §4.1)

## 5. ตรวจ `leaves.status` และ `leave_approvals.status` (DB-RISK-007)

| ตาราง.คอลัมน์ | ชนิดจริง | Default |
|---|---|---|
| `leaves.status` | `enum('Draft','Pending','Approved','Rejected','Cancelled')` | `Draft` |
| `leaves.cancellation_status` | `enum('None','Pending','Approved','Rejected')` | `None` |
| `leaves.substitute_status` | `enum('Pending','Accepted','Rejected')` NULL | `NULL` |
| `leaves.leave_period` | `enum('full_day','morning','afternoon')` | `full_day` |
| `leave_approvals.status` | `enum('Pending','Approved','Rejected')` | `Pending` |
| `leave_adjustments.status` | `enum('Pending','Approved','Rejected')` | `Pending` |
| `leave_evidences.verification_status` | `enum('pending','verified','rejected')` | `pending` |

### ผลตรวจ: ยืนยันว่าเป็นข้อบกพร่องจริง แต่**ไม่ใช่ schema drift**

`leaves.status` **ไม่มีค่า `'Voided'`** ทั้งใน schema จริงและใน schema ที่สร้างจาก migration
(ยืนยันแล้วว่า `expected_schema` ก็ได้ enum ชุดเดียวกันเป๊ะ) — แปลว่า migration ไม่เคยเพิ่มค่านี้เลย

แต่โค้ด `app/Http/Controllers/LeaveGovernanceController.php::void()` สั่ง
`$leave->update(['status' => 'Voided', ...])`

**การทดลองจริง** (ทำบนสำเนาที่ restore แล้วใน container แยก ไม่แตะ production):

```sql
UPDATE eleave.leaves SET status='Voided' WHERE id=1;
-- ERROR 1265 (01000): Data truncated for column 'status' at row 1
```

| ประเด็น | ผล |
|---|---|
| ประเภทปัญหา | **Code ↔ Schema contract mismatch** ไม่ใช่ drift |
| ผลกระทบ | ฟีเจอร์ "ยกเลิกเอกสารโดยคงประวัติ (void)" **ใช้งานไม่ได้เลย** — จะได้ HTTP 500 ทุกครั้ง |
| ความเสี่ยงข้อมูลเสียหาย | **ไม่มี** — `STRICT_TRANS_TABLES` ทำให้ error และ `DB::transaction` ใน `void()` rollback ทั้งก้อน → fail-safe |
| ข้อมูลปัจจุบัน | `leaves` ที่มี `status='Voided'` = **0 แถว**, `voided_at IS NOT NULL` = **0 แถว** → ยังไม่เคยมีใครใช้ฟีเจอร์นี้สำเร็จ |
| ปรับระดับความรุนแรง | จาก "CRITICAL / data loss" → **HIGH / ฟีเจอร์เสีย แต่ล้มอย่างปลอดภัย** |

### Recommended repair (ยังไม่ดำเนินการ — รออนุมัติ LEGACY-01)

| หัวข้อ | รายละเอียด |
|---|---|
| Expected schema | `leaves.status` ต้องรับค่า `Voided` ได้ (โค้ดเขียนค่านี้) |
| Actual schema | `enum('Draft','Pending','Approved','Rejected','Cancelled')` |
| Difference | ขาดค่า `Voided` |
| Risk ถ้าแก้ | `ALTER TABLE leaves MODIFY status ...` บนตารางนี้ใช้เวลานาน (ดู §4) — ต้องมี maintenance window |
| ทางเลือก A (แนะนำ) | เปลี่ยน `enum` → `varchar(20)` + `CHECK` constraint ตาม `LEGACY-MIGRATION-PLAN` 03-9 — แก้ปัญหาการเพิ่มสถานะในอนาคตไปพร้อมกัน |
| ทางเลือก B (เร็วกว่า) | `ALTER TABLE leaves MODIFY status enum(...,'Voided') NOT NULL DEFAULT 'Draft'` — แก้เฉพาะหน้า |
| ทางเลือก C (ไม่แตะ schema) | แก้โค้ดให้ใช้ `status='Cancelled'` + `voided_at`/`void_reason` ที่มีอยู่แล้วเป็นตัวแยกความหมาย |
| ข้อควรระวัง | เลือกทางไหนต้องมี test ครอบก่อน เพราะปัจจุบันไม่มี test ครอบ `void()` เลย |

## 6. ตารางทั้งหมด (55)

`approval_steps`, `attendances`, `cache`, `cache_locks`, `departments`, `document_templates`, `failed_jobs`,
`fiscal_years`, `holidays`, `job_batches`, `jobs`, `leave_action_logs`, `leave_adjustments`, `leave_approvals`,
`leave_audit_logs`, `leave_balances`, `leave_document_sequences`, `leave_evidences`, `leave_policies`,
`leave_types`, `leaves`, `menu_permissions`, `menus`, `migrations`, `model_has_permissions`, `model_has_roles`,
`notifications`, `password_reset_tokens`, `permissions`, `personal_access_tokens`, `personal_data_access_logs`,
`personnel_types`, `role_has_permissions`, `roles`, `saraban_audit_logs`, `saraban_document_attachments`,
`saraban_document_circular_recipients`, `saraban_document_circulars`, `saraban_document_incoming`,
`saraban_document_outgoing`, `saraban_document_receivers`, `saraban_document_sequences`,
`saraban_document_signatures`, `saraban_document_types`, `saraban_document_user_states`,
`saraban_document_workflow_steps`, `saraban_document_workflow_templates`, `saraban_documents`, `sessions`,
`setting_audit_logs`, `settings`, `user_delegations`, `user_profiles`, `users`, `work_calendar_exceptions`

> หมายเหตุ: มี 3 ตารางที่ไม่ได้อยู่ในรายการของ Assessment รอบแรกเพราะสร้างโดย migration ที่อ่านไม่ละเอียด —
> `menu_permissions`, `setting_audit_logs`, `cache_locks` · และ **ไม่มี** ตาราง `leave_eligibility_rules`
> (กฎ eligibility เก็บเป็น JSON ในคอลัมน์ `leave_policies.eligibility_rules`) · Model `App\Models\LeaveRequest`
> ไม่มีตารางรองรับ = orphan model

## 7. ไฟล์หลักฐาน

| ไฟล์ | เนื้อหา |
|---|---|
| `schema-actual-2026-08-12.sql` | DDL จริงจาก production (64,107 bytes) |
| `schema-expected-2026-08-12.sql` | DDL ที่ได้จากการรัน migration ใหม่จากศูนย์ (63,796 bytes) |
| `norm-schema-actual-2026-08-12.txt` / `norm-schema-expected-2026-08-12.txt` | เวอร์ชัน normalize สำหรับ diff |
| `schema-diff.txt` | **ว่างเปล่า (0 บรรทัด)** = ไม่มีความต่าง |
