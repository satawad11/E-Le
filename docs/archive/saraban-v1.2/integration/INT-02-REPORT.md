# INT-02 — MODULE REGISTRY + PERMISSION NAMESPACE

**Source of Truth:** `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md`
**Checkpoint:** INT-02 (ROADMAP บรรทัดที่ 3)
**วันที่:** 2026-09-02
**Branch:** `chore/remove-saraban-module`
**สถานะ:** ✅ ผ่าน Definition of Done ของเช็คพอยต์นี้

---

## DONE

ลงทะเบียนสารบรรณเป็น **โมดูลหนึ่งของระบบเดิม** (SoT §7) และสร้าง permission namespace
ครบตาม SoT §6 โดยเสียบเข้ากับกลไกกลางที่มีอยู่แล้วทุกตัว **ไม่ได้สร้างกลไกใหม่แม้แต่ตัวเดียว**

| สิ่งที่ทำ | REUSE / EXTEND / CREATE |
|---|---|
| ลงทะเบียนโมดูล `saraban` ในทะเบียนโมดูลกลาง | **EXTEND** `config/module_registry.php` |
| permission namespace 31 ชื่อ ครอบคลุม 15 กลุ่ม | **EXTEND** `PermissionCatalogSeeder` (แหล่งความจริงเดียวที่มีอยู่แล้ว) |
| ส่งชื่อสิทธิ์ถึงระบบที่ติดตั้งไปแล้ว | **CREATE** migration 1 ตัว (เรียก seeder เดิม ไม่คัดลอกรายชื่อ) |
| การเปิด-ปิดโมดูล / ด่าน route / แถบเมนู / หน้าจัดการโมดูล | **REUSE 100%** — ไม่แตะโค้ดเลย |

### สิ่งที่ได้มาฟรีจากการ REUSE (ไม่ได้เขียนโค้ดเพิ่ม)

เพราะเสียบเข้ากลไกกลาง สารบรรณจึงได้ความสามารถเหล่านี้ทันทีโดยไม่ต้องเขียนอะไร:

- เปิด-ปิดโมดูลได้จากหน้า "โมดูลระบบ" เดิม (บันทึกลง `module_saraban_enabled`)
- ปิดแล้ว route `api/saraban*` คืน 404 ทั้งชุดผ่าน `EnsureModuleEnabled`
- ปิดแล้วเมนูหายทั้งกิ่งผ่าน `MenuService`
- สิทธิ์ `saraban.*` โผล่ในหน้าจัดการสิทธิ์รายบุคคลเดิม
- ผ่าน `PermissionEvaluator` จึงได้ explicit deny + scope + alias อัตโนมัติ

### การตัดสินใจสำคัญ 4 ข้อ

| # | เรื่อง | ตัดสิน | เหตุผล |
|---|---|---|---|
| 1 | **`dependencies` ของโมดูล** | `['system']` เท่านั้น — **แก้จากที่ INT-00/INT-01 เคยระบุว่า `['system','notification']`** | ถ้าประกาศว่าพึ่งพาศูนย์แจ้งเตือน การปิดศูนย์แจ้งเตือนจะทำให้ลงทะเบียนรับหนังสือไม่ได้ทั้งระบบ ซึ่งขัด SoT §16 ที่ห้ามให้ธุรกรรมล้มเพราะช่องทางแจ้งเตือนล้ม · `NotificationCenter::emit()` คืน `null` เงียบ ๆ อยู่แล้วเมื่อโมดูลถูกปิด · โมดูลการลาและลงเวลาก็ประกาศแบบเดียวกันทั้งที่ส่งแจ้งเตือนเหมือนกัน |
| 2 | **ยังไม่เพิ่มเมนู** | เลื่อนไปพร้อมหน้าจอจริง | `MenuIntegrityTest` บังคับ **สองทาง**: ทุกเมนูต้องมีหน้าจอใน `frontend/src/router/index.js` และทุกหน้าที่ต้องล็อกอินต้องมีเมนูชี้ถึง — เมนูที่ไม่มีหน้าจอจะกลายเป็นเมนูที่กดแล้ว 404 · เพิ่มเทสต์ล็อกสถานะนี้ไว้ว่า **เป็นความตั้งใจ ไม่ใช่ของตกหล่น** |
| 3 | **ยังไม่สร้าง `SarabanModuleServiceProvider`** | เลื่อนไปพร้อม Policy ตัวแรก | provider ของโมดูลมีไว้ผูก `Gate::policy()` ตอนนี้ยังไม่มี model ให้ผูก การสร้างไว้เปล่า ๆ คือ dead code · `overtime` และ `reporting` ก็ประกาศ `providers => []` เหมือนกัน |
| 4 | **migration เรียก seeder เดิม ไม่คัดลอกรายชื่อ** | `(new PermissionCatalogSeeder)->run()` | คัดลอก 31 ชื่อไปไว้ใน migration = สร้างแหล่งความจริงที่สอง ซึ่งเป็นปัญหาที่สารบัญสิทธิ์ตั้งขึ้นมาแก้ตั้งแต่แรก · ตรงกับ precedent ของ `2026_08_28_100000_seed_permission_catalog.php` |

---

## CHANGED

### ข้อค้นพบที่ต้องแก้ของเดิม (2 จุด — ทั้งคู่เป็น fixture ของเทสต์ ไม่ใช่พฤติกรรมระบบ)

| ไฟล์ | เดิม | เหตุที่ต้องแก้ |
|---|---|---|
| `tests/Feature/ModuleRegistryTest.php` | ยืนยันรายชื่อโมดูลเป็น 6 ตัวแบบตายตัว | การเพิ่มโมดูลคือสิ่งที่เทสต์นี้ตั้งใจเฝ้าอยู่ — เพิ่ม `saraban` ที่ตำแหน่ง order 30 |
| `tests/Feature/ModuleToggleTest.php` | ใช้ **`saraban`** เป็นตัวอย่าง "โมดูลที่ไม่รู้จัก" | เมื่อลงทะเบียนโมดูลแล้ว ชื่อนี้กลายเป็นโมดูลที่ระบบรู้จัก เทสต์จึงพิสูจน์สิ่งที่ตั้งใจไม่ได้อีก → เปลี่ยนเป็น `not_a_real_module` |

**ไม่มีการเปลี่ยนพฤติกรรมของระบบเดิมแม้แต่จุดเดียว** — ไม่แตะ controller, service, model,
route, หน้าเว็บ หรือ schema ของโมดูลใดเลย

---

## FILES

**เพิ่มใหม่ (4):**

```
backend/database/migrations/2026_09_02_100000_register_saraban_permission_namespace.php
backend/tests/Feature/SarabanModuleRegistrationTest.php
frontend/tests/adminModulesView.test.js
docs/saraban-integration/INT-02-REPORT.md
```

**แก้ไข (5):**

```
backend/config/module_registry.php                     + manifest โมดูล saraban
backend/database/seeders/PermissionCatalogSeeder.php   + ชื่อสิทธิ์ saraban 31 ชื่อ
backend/tests/Feature/ModuleRegistryTest.php           อัปเดตรายชื่อโมดูลที่คาดหวัง
backend/tests/Feature/ModuleToggleTest.php             เปลี่ยนตัวอย่างโมดูลที่ไม่รู้จัก
backend/tests/Feature/PermissionCatalogTest.php        + ด่านกัน manifest กับสารบัญแตกคอกัน
```

**เอกสารที่แก้ให้ตรงกับความจริงหลังลงมือทำ (3):**
`CORE-COMPATIBILITY-MATRIX.md`, `INT-01-CORE-CONTRACT.md` — แก้ `dependencies` จาก
`['system','notification']` เป็น `['system']` และสถานะของ `SarabanModuleServiceProvider`

---

## MIGRATIONS

`2026_09_02_100000_register_saraban_permission_namespace.php`

| ประเด็น | ผล |
|---|---|
| Backward compatible | ✅ สร้าง **ชื่อสิทธิ์อย่างเดียว ไม่แจกให้บทบาทหรือผู้ใช้คนใด** ขอบเขตของทุกคนเท่าเดิมทุกประการ |
| Idempotent | ✅ `firstOrCreate` — รันซ้ำได้ |
| รันจากฐานว่างได้ | ✅ ยืนยันผ่าน `RefreshDatabase` ทุกเทสต์ และ CI รัน `migrate --force` จาก MySQL 8 เปล่า |
| `down()` | จงใจไม่ย้อนกลับ — การลบชื่อสิทธิ์จะตัดสิทธิ์ที่ผู้ดูแลมอบหมายไว้หลังจากนี้ไปด้วย (ตรงกับ precedent ของ migration สารบัญเดิม) |

**เหตุผลที่ต้องมี migration ตัวนี้:** `2026_08_28_100000_seed_permission_catalog.php`
เรียกสารบัญไปแล้วครั้งหนึ่ง และ migration รันได้ครั้งเดียว ระบบที่ติดตั้งไปแล้วจึงจะ
**ไม่ได้** ชื่อ `saraban.*` เลยถ้าไม่มีตัวนี้ — ชื่อจะมีเฉพาะบนเครื่องที่ติดตั้งใหม่
เป็นรอยรั่วแบบเดียวกับที่ CLEANUP-01 (D5) เพิ่งแก้ไป

---

## TESTS

### เพิ่มใหม่

| ไฟล์ | จำนวน | ครอบคลุม |
|---|---|---|
| `SarabanModuleRegistrationTest` | **12 / 119 assertions** | การลงทะเบียนโมดูล, dependency, การเปิด-ปิด, ด่าน route ไม่ชนกัน, ครบ 15 กลุ่มสิทธิ์, รูปแบบชื่อ, migration ส่งถึงระบบเดิม, ไม่แจกสิทธิ์ให้ใคร, ผู้ใช้ทั่วไปไม่มีสิทธิ์, ยังไม่มีเมนู, แสดงในหน้าจัดการโมดูลเดิม, ปิดแล้วเมนูไม่โผล่ |
| `PermissionCatalogTest` (+1 เมธอด) | +1 | manifest ของทุกโมดูลต้องประกาศสิทธิ์ที่มีในสารบัญ |
| `frontend/tests/adminModulesView.test.js` | **5** | UI QA — ดูหัวข้อ UI-QA |

### ด่านที่ปิดช่องว่างจริงของระบบเดิม

`test_every_permission_a_module_manifest_declares_exists_in_the_catalogue`

ชื่อสิทธิ์ถูกสร้างจาก **สองทางที่ไม่เคยคุยกัน** — `PermissionCatalogSeeder`
(ซึ่งประกาศตัวเองเป็นแหล่งความจริงเดียว) และ `ModulePermissionSeeder` ที่สร้างจาก
`config('modules.*.permissions')` อีกทาง ทั้งคู่ถูกเรียกใน `DatabaseSeeder` เหมือนกัน
แต่ไม่มีอะไรบังคับให้ตรงกัน ถ้าโมดูลประกาศสิทธิ์ที่ไม่อยู่ในสารบัญ ชื่อนั้นจะถูกสร้าง
ตอนติดตั้งจริงแต่ `PermissionCatalogTest` มองไม่เห็น — รอยรั่วแบบเดียวกับ CLEANUP-01 (D5)
**ด่านนี้ผ่านทันทีกับโมดูลเดิมทั้ง 6 ตัว** จึงไม่ได้บังคับให้ใครต้องตามแก้ของเก่า

### สิ่งที่เทสต์ยืนยันเรื่อง Default DENY

- `installing_creates_every_saraban_permission_but_grants_them_to_nobody` — ไม่มีบทบาทใดถือสิทธิ์ `saraban.*`
- `an_ordinary_user_holds_no_saraban_permission` — ผู้ใช้ทั่วไปถูกปฏิเสธทั้ง 31 ชื่อ
- `an_already_installed_system_receives_the_namespace_through_a_migration` — ยืนยัน `role_has_permissions` ยังว่าง

---

## SECURITY

| ประเด็น | ผล |
|---|---|
| Default DENY | ✅ สร้าง 31 ชื่อ แต่ **ไม่แจกให้ใครเลย** — พิสูจน์ด้วยเทสต์ 3 ข้อ |
| ขอบเขตผู้ใช้เดิมเปลี่ยนไหม | ❌ ไม่เปลี่ยน — migration สร้างชื่ออย่างเดียว ไม่แตะ `role_has_permissions` / `model_has_permissions` |
| พื้นผิวการโจมตีใหม่ | ไม่มี — INT-02 ไม่เพิ่ม endpoint, ไม่เพิ่มหน้าจอ, ไม่เพิ่ม dependency |
| ชื่อสิทธิ์ผิดรูป | `ModuleRegistry::normalize()` โยน exception ตั้งแต่ boot ถ้าผิดรูป `module.resource.action` — มีเทสต์ยืนยันทั้ง 31 ชื่อ |
| การเปิด-ปิดโมดูล | ต้องมีสิทธิ์ `system.setting.manage` (ด่านเดิม) — ไม่ได้ผ่อนอะไร |
| Frontend เป็น security boundary ไหม | ไม่ — สิทธิ์ทั้งหมดตัดสินฝั่ง server ผ่าน `PermissionEvaluator` ตาม SoT §6 |
| `SarabanCoreContractTest` | ✅ ยังเขียว — ยืนยันว่า INT-02 ไม่ได้สร้าง Core ซ้ำ |

---

## REGRESSION

| รอบ | ผล |
|---|---|
| Baseline INT-01 | 721 passed · 8 skipped · 0 failed |
| **Backend หลัง INT-02** | ✅ **741 passed · 8 skipped · 0 failed** · 2678 assertions · 71.41s · exit 0 |
| **Frontend หลัง INT-02** | ✅ **64 passed** (6 ไฟล์) · 4.90s |
| Pint (`--test`) | ✅ ผ่านทุกไฟล์ที่แตะ |
| ESLint | ✅ ผ่าน |
| vue-tsc typecheck | ✅ ผ่าน |

**ขอบเขต regression:** รันเต็มชุดทั้ง backend และ frontend ไม่ได้รันเฉพาะเทสต์ที่เกี่ยวข้อง
ครอบคลุมโมดูลเดิมทั้งหมด (`leave`, `attendance`, `overtime`, `notification`, `reporting`)
และระบบกลาง (menu, permission, module toggle, secure file, audit, workflow)

> **หมายเหตุเรื่องตัวเลข:** จำนวนเทสต์ 721 → 741 ไม่ได้มาจาก INT-02 ทั้งหมด
> งานนี้เพิ่ม 13 กรณี (12 + 1) ส่วนที่เหลือมาจากงานค้างของอีกงานหนึ่งที่ทำอยู่ใน
> working tree เดียวกัน (มี `thaiUiAndAccessibility.test.js` เพิ่มเข้ามาฝั่ง frontend ด้วย)
> INT-02 ไม่ได้แตะไฟล์ของงานนั้นเลย

---

## UI-QA

**ไม่ได้แก้ไฟล์หน้าเว็บแม้แต่บรรทัดเดียว** — โมดูลสารบรรณแสดงในหน้า "โมดูลระบบ" เดิม
ด้วยการ์ด/สวิตช์/badge ชุดเดิมทั้งหมด ตรงตาม SoT §2 (UI RULE — EXISTING SYSTEM FIRST)

**วิธีตรวจ:** เขียนเป็นเทสต์อัตโนมัติแทนการดูด้วยตา เพราะเป็นหลักฐานที่รันซ้ำได้ใน CI
(`frontend/tests/adminModulesView.test.js` — mount หน้าจอจริงด้วย `@vue/test-utils`
แล้วป้อน payload รูปร่างเดียวกับที่ `ModuleRegistry::describe()` คืนจริง)

| สิ่งที่ตรวจ | ผล |
|---|---|
| การ์ดแสดงชื่อ + คำอธิบายภาษาไทย ด้วยการ์ดเดิมของหน้าจอ | ✅ |
| badge "เปิดใช้งาน" แสดงถูกต้อง | ✅ |
| แสดง "ต้องใช้ร่วมกับ: system" | ✅ |
| มีสวิตช์ปิดได้ (`aria-label` ภาษาไทยครบ) | ✅ |
| กดสวิตช์แล้วส่ง **`config_key`** ไม่ใช่ `code` ไปยัง endpoint เดิม | ✅ |
| ไม่มีหน้าจอ/เส้นทาง/แถบเมนูใหม่ของสารบรรณโผล่มา | ✅ |

**Design tokens / a11y:** ไม่ได้แตะ `style.css`, ไม่เพิ่ม `@theme`, ไม่เพิ่มสี hard-code,
ไม่เพิ่ม UI kit ใหม่ · `icon` ของโมดูลใช้ `document` ซึ่งเป็นคีย์ที่มีอยู่จริงใน
`frontend/src/utils/icons.js` แล้ว

> **ข้อจำกัดที่ต้องบอกตรง ๆ:** ไม่ได้ทำ visual QA ด้วยการเปิดเบราว์เซอร์จริง เพราะการเข้าสู่ระบบ
> ต้องกรอกรหัสผ่าน ซึ่งผมไม่กรอกให้ · เทสต์ mount หน้าจอจริงจึงเป็นหลักฐานแทน
> ถ้าต้องการภาพหน้าจอประกอบ ผู้ดูแลเข้าที่ **http://localhost:8001 → เมนู "โมดูลระบบ"**
> จะเห็นการ์ด "ระบบงานสารบรรณอิเล็กทรอนิกส์" เพิ่มขึ้นมาระหว่างงานล่วงเวลากับศูนย์การแจ้งเตือน

---

## EVIDENCE

| หลักฐาน | ที่มา / ผล |
|---|---|
| โมดูลอยู่ในทะเบียนกลาง | `config/module_registry.php` — `saraban`, order 30, deps `['system']`, pattern `api/saraban*` |
| ลำดับโมดูลหลังเพิ่ม | `['system','leave','attendance','overtime','saraban','notification','reporting']` (ยืนยันใน `ModuleRegistryTest`) |
| สิทธิ์ 31 ชื่อ 15 กลุ่ม | `PermissionCatalogSeeder::MODULE_PERMISSIONS` + manifest — ตรงกันทั้งคู่ (บังคับด้วยเทสต์) |
| ส่งถึงระบบที่ติดตั้งแล้ว | migration `2026_09_02_100000` + เทสต์ที่ไม่ seed อะไรเลยแล้วยังเจอชื่อครบ |
| ไม่แจกสิทธิ์ให้ใคร | `assertDatabaseCount('role_has_permissions', 0)` + ผู้ใช้ทั่วไป `can()` = false ทั้ง 31 ชื่อ |
| เทสต์เช็คพอยต์ | `php artisan test --filter=SarabanModuleRegistrationTest` → **12 passed (119 assertions)** |
| เทสต์กลุ่มที่กระทบ | `--filter="SarabanModuleRegistrationTest\|ModuleRegistryTest\|ModuleToggleTest\|PermissionCatalogTest\|SarabanCoreContractTest"` → **40 passed (385 assertions)** |
| Regression backend | `php artisan test` → **741 passed, 8 skipped, 0 failed**, exit 0 |
| Regression frontend | `npm test` → **64 passed** (6 ไฟล์) |
| UI QA | `frontend/tests/adminModulesView.test.js` → **5 passed** |
| Style / Lint / Types | `pint --test` ✅ · `eslint .` ✅ · `vue-tsc --noEmit` ✅ |

---

## RISKS

| รหัส | ความเสี่ยง | ระดับ | สถานะ |
|---|---|---|---|
| RISK-01 | ข้อมูลหนังสือของสารบรรณรุ่นเก่าอาจสูญไปแล้ว (migration ถอดโมดูลเป็นทางเดียว) | **สูง** | **ยังเปิด — ต้องตอบก่อน INT-03** ซึ่งเป็นเช็คพอยต์ถัดไป |
| RISK-03 | `personnel_assignments.position` เป็น string → Authority Matrix ไม่แม่น | กลาง | แก้ที่ INT-03 |
| RISK-11 **(ใหม่)** | สิทธิ์ 31 ชื่อมีอยู่แต่ยังไม่มี route/หน้าจอใดใช้ — ถ้าเช็คพอยต์ถัดไปเปลี่ยนชื่อ จะเหลือชื่อกำพร้าที่ผู้ดูแลอาจมอบหมายไปแล้ว | ต่ำ | ชื่อทั้งหมดมาจาก SoT §6 โดยตรง และผูกกับ 15 กลุ่มความสามารถที่ระบุไว้ · ถ้าจำเป็นต้องเปลี่ยน ให้ใช้ `permission_aliases` ที่ระบบมีอยู่แล้ว ไม่ใช่ลบทิ้ง |
| RISK-12 **(ใหม่)** | โมดูลเปิดใช้งานโดยค่าเริ่มต้น (`MODULE_SARABAN_ENABLED` default `true`) แต่ยังไม่มีฟีเจอร์ — ผู้ดูแลเห็นการ์ดโมดูลที่ "เปิดอยู่แต่ไม่มีอะไร" | ต่ำ | ตั้งใจ — ให้ตรงกับโมดูลอื่นและให้เช็คพอยต์ถัดไปมองเห็นผลทันทีโดยไม่ต้องไปพลิกสวิตช์ · ปิดได้ทันทีจากหน้าจอถ้าต้องการ · ไม่มี route/เมนูจึงไม่มีผลกับผู้ใช้ทั่วไป |

**ไม่มี blocker สำหรับ INT-03** (นอกจาก RISK-01 ที่ต้องได้คำตอบก่อนออกแบบ schema)

---

## NEXT

**→ INT-03 — Data Model + Core References**

**ต้องได้คำตอบก่อนเริ่ม (RISK-01):** production เคยมีข้อมูลสารบรรณจริงหรือไม่
และมี backup ก่อน commit `cd34f41` หรือไม่ — เป็นตัวตัดสินระหว่าง **MIGRATE** กับ **CREATE**
ถ้าเคยมีข้อมูลจริงแล้วสร้าง schema ใหม่ทับ ข้อมูลเดิมจะกู้ไม่ได้อีก

งานที่วางไว้:

1. เพิ่ม `positions` + `personnel_assignments.position_id` (nullable, additive, backfill จาก string เดิม) — แก้ RISK-03 ให้ Authority Matrix resolve ตามตำแหน่งได้แม่น
2. Schema แกนของสารบรรณ — `saraban_documents`, `saraban_document_versions`,
   `saraban_document_types` โดยอ้าง `user_id` / `org_unit_id` / `position_id` ของ Core เท่านั้น
3. ยืนยันด้วย `SarabanCoreContractTest` ที่มีอยู่แล้วว่าไม่มีตารางใดซ้ำกับทะเบียนหลักของ Core
   และไม่มีคอลัมน์ `department_id`
4. Regression เต็มชุดทั้งสองฝั่ง
