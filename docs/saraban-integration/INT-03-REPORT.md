# INT-03 — DATA MODEL + CORE REFERENCES

**Source of Truth:** `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md`
**Checkpoint:** INT-03 (ROADMAP บรรทัดที่ 4)
**วันที่:** 2026-09-03
**Branch:** `chore/remove-saraban-module`
**สถานะ:** ✅ ผ่าน Definition of Done ของเช็คพอยต์นี้

---

## RISK-01 — ปิดด้วยหลักฐาน ไม่ใช่ด้วยการสันนิษฐาน

เจ้าของระบบยืนยันว่า "เคยมีแต่ลบไปแล้ว" ก่อนตัดสินใจสร้าง schema ใหม่ทับ
จึงตรวจสถานะจริงก่อน เพราะเป็นจุดที่ย้อนกลับยาก **และพบเรื่องที่ต่างจากที่เข้าใจกัน**

| สิ่งที่ตรวจ | ผล |
|---|---|
| ตาราง `saraban_*` ในฐานข้อมูลจริง | **0 ตาราง** — migration ถอดโมดูลรันไปแล้ว |
| **สำรองข้อมูลก่อนถอดโมดูล** | **มีอยู่จริง** — `storage/backups/Laravel/2026-08-19-02-00-00.zip` (สำรองเมื่อ 2026-08-18) ซึ่ง **ก่อนวันถอดโมดูล (commit `cd34f41`, 2026-08-27 22:16) ถึง 9 วัน** |
| ในไฟล์สำรองมีอะไร | ครบทั้ง 14 ตาราง `saraban_*` + ไฟล์แนบ PDF ใน `private/uploads/saraban/attachments/` |
| **มีข้อมูลหนังสือจริงหรือไม่** | **ไม่มี** — มีเพียงตารางเดียวที่มีข้อมูลคือ `saraban_document_types` (ข้อมูลอ้างอิงที่ seeder สร้าง: "หนังสือภายนอก/ภน.", "หนังสือภายใน/ภย." เวลา 2026-08-19 01:57:58) · `saraban_documents`, incoming, outgoing, signatures, attachments **ว่างทั้งหมด** |
| ไฟล์ PDF ที่พบ | ทุกไฟล์ขนาดเท่ากันเป๊ะ (192,415 ไบต์) และไม่มีแถวใน `saraban_document_attachments` อ้างถึงเลย — เป็นไฟล์ทดสอบที่อัปโหลดซ้ำ ไม่ใช่หนังสือราชการ |

**ข้อสรุป: ไม่มีหนังสือราชการที่ต้อง MIGRATE → ตัดสินเป็น CREATE**
สิ่งที่หายไปคือข้อมูลอ้างอิงประเภทหนังสือ ซึ่งจะถูกสร้างใหม่อยู่แล้ว

> **สิ่งที่ควรรู้ไว้:** ไฟล์สำรอง 2026-08-19 ยังอยู่ และเป็นชุดเดียวที่มีสถานะก่อนถอดโมดูล
> `spatie/laravel-backup` มีกติกาลบไฟล์เก่าอัตโนมัติ ถ้าต้องการเก็บไว้เป็นหลักฐานว่า
> "ไม่มีหนังสือราชการสูญหาย" (SoT §23 Compliance Evidence) ควรคัดลอกออกไปเก็บต่างหาก
> ผมไม่ได้แตะไฟล์สำรองใด ๆ — เปิดอ่านอย่างเดียวแล้วลบไฟล์ที่แตกไว้ชั่วคราวทิ้ง

---

## DONE

วางโครงข้อมูลแกนของสารบรรณ และปิดช่องว่างของ Core ที่ Authority Matrix ต้องใช้

| สิ่งที่ทำ | ประเภท | SoT |
|---|---|---|
| ตาราง `positions` + `personnel_assignments.position_id` (nullable + backfill) | **EXTEND Core** | §13 |
| `saraban_document_types` · `saraban_documents` · `saraban_document_versions` | **CREATE (โดเมนของสารบรรณ)** | §5, §8 |
| Model 4 ตัว พร้อมความสัมพันธ์ | CREATE | §8 |
| ทำให้ด่านสัญญาตรวจ "โค้ด" ไม่ใช่ "คำอธิบาย" | **MODIFY เทสต์ของตัวเอง** | RISK-09 |

### หลักที่ยึดในการออกแบบ

1. **หนังสือเป็น entity หลัก · PDF เป็นภาพแทนของแต่ละเวอร์ชัน** (SoT §8) —
   สายงานจะผูกกับ `document_id + document_version_id` ไม่ใช่ผูกกับไฟล์
2. **ต้นฉบับห้ามถูกเขียนทับ** — ทุกการแก้ไขคือแถวใหม่ใน `saraban_document_versions`
   มี `unique(document_id, version_no)` บังคับ
3. **ทุกการอ้างถึงคน/หน่วยงานเป็น id ของ Core** — `users.id`, `org_units.id`
   ตั้ง `restrictOnDelete` เพราะหนังสือราชการเป็นหลักฐาน ปล่อยให้ผู้อ้างอิงหายไปไม่ได้
4. **ไม่เดาโครงสร้างล่วงหน้า** — ยังไม่ใส่คอลัมน์เลขหนังสือ (INT-06) และการผูกสายงาน
   (INT-08) เพราะจะต้องมาแก้ทีหลัง ใส่เฉพาะที่ INT-03 รับผิดชอบจริง

### RISK-03 ปิดแล้ว — ตำแหน่งเทียบด้วย id ได้แล้ว

`personnel_assignments.position` เป็นข้อความอิสระ ทำให้ "หัวหน้างานพัสดุ" กับ
"หัวหน้างานพัสดุ " (มีช่องว่างท้าย) นับเป็นคนละตำแหน่ง — Authority Matrix (SoT §13)
และการเดินสายงานตามตำแหน่ง (SoT §11) จึงแม่นไม่พอ

แก้แบบ **เพิ่มอย่างเดียว ไม่ถอนของเดิม**: สร้าง `positions` จากชื่อที่มีอยู่จริง
(ตัดช่องว่างหัวท้ายก่อนเทียบ ยุบชื่อซ้ำ ข้ามชื่อว่าง) แล้วเพิ่ม `position_id` แบบ nullable
**คงคอลัมน์ `position` เดิมไว้ทั้งหมด** โค้ดเดิมทุกจุดจึงทำงานเหมือนเดิม

เป็นทะเบียนของ **ระบบกลาง** ไม่ใช่ของสารบรรณ — โมดูลอื่นใช้ร่วมได้ ตรงตาม SoT §4

---

## CHANGED

**ไม่มีการเปลี่ยนพฤติกรรมของระบบเดิม** — ไม่แตะ controller, service, route หรือหน้าเว็บใด ๆ

แก้ไฟล์เดิม 2 จุด ทั้งคู่เป็นการเพิ่ม ไม่ใช่การแก้พฤติกรรม:

| ไฟล์ | การแก้ |
|---|---|
| `app/Models/PersonnelAssignment.php` | เพิ่ม `position_id` ใน `$fillable` + ความสัมพันธ์ `positionRef()` — ของเดิมไม่ถูกแตะ |
| `tests/Feature/SarabanCoreContractTest.php` | ทำให้ตัวสแกนตัดคอมเมนต์ออกก่อนตรวจ (ดูหัวข้อถัดไป) |

### ด่านของตัวเองจับตัวเองได้ — และเป็นความเสี่ยงที่บันทึกไว้ล่วงหน้าแล้ว

รัน regression แล้ว `SarabanCoreContractTest` **ล้ม** โดยชี้ว่า `Document.php`
ฝ่าฝืนกฎ "ห้ามใช้ `department_id`"

ตรวจแล้วพบว่าโค้ดสะอาด — ที่ถูกจับคือ **คอมเมนต์ที่เขียนอธิบายว่าห้ามใช้ `department_id`**
ซึ่งตรงกับ **RISK-09 ที่บันทึกไว้ตั้งแต่ INT-01** ว่าด่านแบบ regex อาจ "จับผิดคนบริสุทธิ์"

**ทางแก้ที่เลือก: ทำให้ด่านฉลาดขึ้น ไม่ใช่ลบคำอธิบายออกจากโค้ด**
ใช้ `token_get_all()` ตัด `T_COMMENT`/`T_DOC_COMMENT` ออกก่อนจับคู่ — ด่านนี้มีไว้ตรวจ
*โค้ด* ไม่ใช่ *คำอธิบาย* และคำอธิบายว่าทำไมถึงห้าม คือสิ่งที่กันไม่ให้คนรุ่นถัดไปทำผิดซ้ำ

ล็อกพฤติกรรมใหม่ด้วยเทสต์ 3 ข้อ: คอมเมนต์ที่อธิบายข้อห้ามต้องไม่ถูกจับ ·
อีเมลในคอมเมนต์ไม่ใช่การ hard-code · **แต่ `department_id` ในโค้ดจริงต้องยังถูกจับได้เหมือนเดิม**

---

## FILES

**เพิ่มใหม่ (7):**

```
backend/database/migrations/2026_09_03_100000_create_positions_and_link_assignments.php
backend/database/migrations/2026_09_03_110000_create_saraban_document_foundation.php
backend/app/Models/Position.php
backend/app/Models/Saraban/DocumentType.php
backend/app/Models/Saraban/Document.php
backend/app/Models/Saraban/DocumentVersion.php
backend/tests/Feature/SarabanDataModelTest.php
docs/saraban-integration/INT-03-REPORT.md
```

**แก้ไข (2):** `app/Models/PersonnelAssignment.php`, `tests/Feature/SarabanCoreContractTest.php`

---

## MIGRATIONS

| Migration | ประเด็นความปลอดภัย |
|---|---|
| `2026_09_03_100000_create_positions_and_link_assignments` | **เพิ่มอย่างเดียว** — `position_id` เป็น nullable, คอลัมน์ `position` เดิมไม่ถูกแตะ, ไม่มีใครเสียข้อมูล · `down()` ถอนได้สะอาด |
| `2026_09_03_110000_create_saraban_document_foundation` | ตารางใหม่ล้วน ไม่แตะตารางเดิม · foreign key ที่อ้างกันเองระหว่าง documents ↔ versions ผูกทีหลังในไฟล์เดียวกัน จึงไม่ต้องปิด foreign key check · `down()` ถอนได้สะอาด |

**ยืนยันด้วยเทสต์จริง ไม่ใช่การอ้าง:**

- `test_the_migration_backfills_positions_from_the_names_already_in_the_data` —
  ถอย migration แล้วใส่ข้อมูลที่มีชื่อซ้ำ / ต่างกันแค่ช่องว่าง / ว่าง / `null`
  แล้วเรียก `up()` ใหม่ เพื่อให้ **โค้ด backfill ตัวจริง** ทำงาน (ใช้รูปแบบเดียวกับ
  `LegacyModernizationMigrationRollbackTest` ที่มีอยู่แล้ว)
- `test_the_saraban_foundation_migration_rolls_back_and_reapplies_cleanly` — `down()` แล้ว `up()` ได้สะอาด
- CI รัน `php artisan migrate --force` จาก MySQL 8 เปล่าทุกครั้ง

> **ยังไม่ได้รัน migration บนฐานข้อมูล dev** โดยเจตนา เพราะ `php artisan migrate` จะรัน
> migration ของงานอื่นที่ค้างอยู่ใน working tree เดียวกัน (`2026_09_01_140000_add_system_identity_to_roles_table`)
> ไปด้วย ซึ่งไม่ใช่ของงานนี้ — เจ้าของระบบรันเองเมื่อพร้อม

---

## TESTS

`SarabanDataModelTest` — **9 กรณี / 31 assertions**

| กลุ่ม | ครอบคลุม |
|---|---|
| §5 การอ้างอิง Core | ไม่มีตารางสารบรรณใดมี `department_id` · หนังสืออ้าง `users`/`org_units` จริง · ลบหน่วยงานที่ยังมีหนังสืออ้างอยู่ไม่ได้ |
| §8 เอกสารและเวอร์ชัน | เก็บทุกเวอร์ชันและต้นฉบับไม่ถูกเขียนทับ · เลขเวอร์ชันซ้ำในเอกสารเดียวกันไม่ได้ · ไฟล์เก็บเป็น path ส่วนตัว ไม่ใช่ URL สาธารณะ |
| §13 ตำแหน่ง | assignment ชี้ตำแหน่งด้วย id ได้ โดยคอลัมน์ข้อความเดิมยังอยู่ · backfill ทำงานถูกกับข้อมูลจริง |
| Migration | ถอย/รันใหม่ได้สะอาด |

`SarabanCoreContractTest` — เพิ่มการยืนยันว่าตัวสแกนแยกโค้ดกับคอมเมนต์ได้ (5 กรณี / 13 assertions)

---

## SECURITY

| ประเด็น | ผล |
|---|---|
| ไฟล์ PDF ของหนังสือ | เก็บเป็น path บน disk `private_uploads` เท่านั้น — มีเทสต์ยืนยันว่าไม่ใช่ URL และไม่มี `/storage/` · การเข้าถึงต้องผ่าน `SecureFileController` ตามสัญญา INT-01 §1.12 |
| ความสมบูรณ์ของหลักฐาน | `restrictOnDelete` บน `owner_org_unit_id` และ `created_by` — ลบหน่วยงาน/ผู้ใช้ที่ยังมีหนังสืออ้างอยู่ไม่ได้ (มีเทสต์ยืนยัน) |
| ต้นฉบับ | คนละแถวกับฉบับแก้ไขเสมอ + `unique(document_id, version_no)` — เขียนทับไม่ได้แม้ตั้งใจ |
| `file_hash` | เตรียมไว้ตั้งแต่ต้นเพราะเป็นหลักฐานที่ลายเซ็นจะผูกไว้ (SoT §14) และใช้เทียบเวอร์ชัน (SoT §22) |
| พื้นผิวการโจมตีใหม่ | ไม่มี — INT-03 ไม่เพิ่ม endpoint, ไม่เพิ่มหน้าจอ, ไม่เพิ่ม dependency |
| ขอบเขตสิทธิ์ผู้ใช้ | ไม่เปลี่ยน — ไม่แตะ `role_has_permissions` / `model_has_permissions` |
| ด่านสัญญา | ✅ ยังเขียว — ไม่มีตารางใดซ้ำกับทะเบียนหลักของ Core |

---

## REGRESSION

| รอบ | ผล |
|---|---|
| Baseline INT-02 | 741 passed · 8 skipped · 0 failed |
| รอบแรกหลัง INT-03 | ⚠️ **1 failed** — ด่านสัญญาของตัวเองจับคอมเมนต์ (ดูหัวข้อ CHANGED) |
| **หลังแก้ด่านให้ตัดคอมเมนต์** | ✅ **750 passed · 8 skipped · 0 failed** · 2712 assertions · 69.44s · exit 0 |
| Pint (`--test`) | ✅ ผ่านทุกไฟล์ที่แตะ |
| Frontend | ไม่ได้แตะหน้าเว็บใน INT-03 — สถานะเดิม 64 passed |

**ขอบเขต:** รันเต็มชุด ครอบคลุมโมดูลเดิมทั้งหมดและระบบกลาง
โดยเฉพาะ `PersonnelDomainCoreServicesTest`, `PersonnelEntityFoundationTest`,
`PersonnelApiAlignmentTest` ที่ใช้ `personnel_assignments` โดยตรง — เขียวทั้งหมด
ยืนยันว่าการเพิ่ม `position_id` ไม่กระทบของเดิม

---

## UI-QA

**ไม่มีการเปลี่ยนแปลงหน้าเว็บใน INT-03** — เช็คพอยต์นี้เป็นชั้นข้อมูลล้วน
ไม่มี endpoint และไม่มีหน้าจอ จึงไม่มีอะไรให้เทียบ

หน้าจอของสารบรรณ (พร้อมเมนู) จะมาในเช็คพอยต์ที่ส่งหน้าจอจริง ตามที่ล็อกไว้ใน
`SarabanModuleRegistrationTest::test_saraban_declares_no_menu_until_its_screens_exist`

---

## EVIDENCE

| หลักฐาน | ที่มา / ผล |
|---|---|
| RISK-01 — ไม่มีหนังสือให้ MIGRATE | ตรวจ dump ใน `2026-08-19-02-00-00.zip`: `INSERT INTO` มีเฉพาะ `saraban_document_types` · `saraban_documents` ไม่มี INSERT เลย |
| RISK-01 — ฐานข้อมูลปัจจุบัน | `SHOW TABLES` → 0 ตาราง `saraban*` · migration ถอดโมดูลมีบันทึกว่ารันแล้ว |
| Backfill ตำแหน่งทำงานจริง | `test_the_migration_backfills_positions_from_the_names_already_in_the_data` — เรียก `up()` ของ migration ตัวจริง |
| ต้นฉบับไม่ถูกเขียนทับ | `test_a_document_keeps_every_version_and_never_overwrites_the_original` |
| ไม่มี `department_id` | `test_no_saraban_table_references_the_single_level_department` |
| Migration ถอย/รันใหม่ได้ | `test_the_saraban_foundation_migration_rolls_back_and_reapplies_cleanly` |
| เทสต์เช็คพอยต์ | `--filter=SarabanDataModelTest` → **9 passed (31 assertions)** |
| Regression | `php artisan test` → **750 passed, 8 skipped, 0 failed**, exit 0 |
| Style | `pint --test` ✅ |

---

## RISKS

| รหัส | ความเสี่ยง | ระดับ | สถานะ |
|---|---|---|---|
| ~~RISK-01~~ | ข้อมูลหนังสือรุ่นเก่าสูญหาย | — | ✅ **ปิด** — ตรวจไฟล์สำรองก่อนถอดโมดูลแล้ว ไม่มีหนังสือราชการเลย มีเพียงข้อมูลอ้างอิง |
| ~~RISK-03~~ | ตำแหน่งเป็นข้อความ ทำให้ Authority Matrix ไม่แม่น | — | ✅ **ปิด** — มี `positions` + `position_id` พร้อม backfill ที่ทดสอบแล้ว |
| ~~RISK-09~~ | ด่านสัญญาอาจจับผิดคนบริสุทธิ์ | — | ✅ **ปิด** — เกิดขึ้นจริงและแก้แล้วด้วยการตัดคอมเมนต์ก่อนตรวจ พร้อมเทสต์ล็อกทั้งสองทาง |
| RISK-13 **(ใหม่)** | `confidentiality` / `urgency` / `status` / `direction` เก็บเป็นข้อความ ยังไม่มีการบังคับค่าที่ระดับฐานข้อมูล | ต่ำ | ตั้งใจ — ตามแนวทางที่ระบบเดิมใช้กับ `leaves.status` (เลี่ยง enum เพื่อให้เพิ่มค่าได้โดยไม่ต้อง ALTER TABLE) · จะบังคับด้วย validation rule ตอนมี endpoint (INT-05) และมีค่าคงที่ในโมเดลกำกับไว้แล้ว |
| RISK-14 **(ใหม่)** | ไฟล์สำรอง 2026-08-19 เป็นชุดเดียวที่มีสถานะก่อนถอดโมดูล อาจถูกลบตามกติกา retention | ต่ำ | ควรคัดลอกออกไปเก็บเป็นหลักฐาน SoT §23 — เป็นการตัดสินใจของเจ้าของระบบ |

**ไม่มี blocker สำหรับ INT-04**

---

## NEXT

**→ INT-04 — File / Audit / Trusted Time**

1. **Malware scan + quarantine** (SEC-GAP-02) — ต้องทำที่ Core File Service ไม่ใช่ในสารบรรณ
   · หมายเหตุ: เครื่องนี้มีคอนเทนเนอร์ `clamav` ของโปรเจกต์อื่นรันอยู่ ต้องยืนยันก่อนว่า
   จะใช้ร่วมหรือเพิ่มของโปรเจกต์นี้เอง (SoT §27 ห้ามเพิ่ม infra เองโดยไม่ตกลง)
2. **โปรไฟล์ `saraban_attachments`** ใน `config/uploads.php` (1 ใน 4 จุดที่ต้องแก้ไฟล์ Core)
3. **`SecureFileAccessService` → resolver registry** (RISK-04) พร้อม regression ของ
   ไฟล์แนบใบลา/รูปลงเวลา/ลายเซ็น
4. **`TrustedClock`** (SoT §10) เป็น seam เดียวของสารบรรณ แล้วเพิ่มกฎ "ห้ามเรียก `now()`
   ตรง ๆ ในโดเมนสารบรรณ" เข้าด่านสัญญา ตามที่ INT-01 §1.15 ตกลงไว้
5. **Audit immutability** (SEC-GAP-03) — hash chain บน `audit_logs` แบบ additive
