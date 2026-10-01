# INT-05 — DOCUMENT CORE

**Source of Truth:** `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md`
**Checkpoint:** INT-05 (ROADMAP บรรทัดที่ 6)
**วันที่:** 2026-09-04
**Branch:** `chore/remove-saraban-module`
**สถานะ:** ✅ ผ่าน Definition of Done ของเช็คพอยต์นี้

---

## DONE

เช็คพอยต์แรกที่ผู้ใช้ **เข้าใช้งานงานสารบรรณได้จริงจากหน้าเว็บ** — สร้างหนังสือ
แนบไฟล์ แก้ไขฉบับร่าง และเสนอเรื่อง โดยหน้าจอทั้งหมดอยู่ใน shell เดิมและใช้
component เดิมของระบบตาม SoT §2

| ส่วน | สิ่งที่ส่งมอบ |
|---|---|
| Authorization | `DocumentPolicy` (object-level, default DENY) + `SarabanModuleServiceProvider` |
| Domain | `DocumentService` — สร้างร่าง / เพิ่มเวอร์ชัน / เสนอเรื่อง |
| API | 6 endpoint ใต้ `/api/saraban/documents` |
| UI | `DocumentsView` (ทะเบียน) + `DocumentFormView` (สร้าง/แก้ไข/ดูรายละเอียด) |
| Menu | กลุ่มเมนู "งานสารบรรณ" 2 รายการ — **ปลดล็อกข้อบังคับที่ตั้งไว้ตั้งแต่ INT-02** |
| File | ไฟล์หนังสือเสิร์ฟผ่าน `SecureFileController` เดิม ตรวจสิทธิ์ด้วย `DocumentPolicy` |

### หลักที่บังคับไว้ในโค้ด (ไม่ใช่แค่เขียนในเอกสาร)

| หลัก (SoT) | บังคับที่ไหน |
|---|---|
| §8 ต้นฉบับห้ามถูกเขียนทับ | `DocumentService::addVersion()` สร้างแถวใหม่เสมอ + `unique(document_id, version_no)` + เทสต์ยืนยันว่าแฮชและ path ของต้นฉบับไม่เปลี่ยน |
| §8 เสนอแล้วแก้ไม่ได้ | `DocumentPolicy::update()` ปฏิเสธเมื่อไม่ใช่ฉบับร่าง — ไม่ใช่ซ่อนปุ่มที่หน้าเว็บ |
| §6 default DENY | ผู้ไม่มีสิทธิ์ถูกปฏิเสธทุก endpoint · สิทธิ์ `view` ไม่ได้แปลว่าสร้างได้ |
| §6 object authorization | เดา id ถูกก็เปิดไม่ได้ถ้าอยู่นอกขอบเขตหน่วยงาน |
| §10 เวลาที่เป็นหลักฐาน | `DocumentService` ใช้ `TrustedClock` ไม่ใช่ `now()` (ด่านสัญญาบังคับอยู่แล้ว) |
| §25 audit กลางชุดเดียว | ทุกการเปลี่ยนสถานะผ่าน `AuditLogger` ไม่มีตาราง audit ของสารบรรณ |
| §26 ไฟล์ต้องผ่านด่านตรวจ | ใช้ `UploadRules::sarabanAttachment()` จึงได้ด่านตรวจไวรัสของ INT-04 อัตโนมัติ |

### การตัดสินใจที่ควรบันทึก

| # | เรื่อง | ตัดสิน | เหตุผล |
|---|---|---|---|
| 1 | **RISK-04 — refactor `SecureFileAccessService` เป็น registry** | **ไม่ทำ และปิดความเสี่ยงนี้** | ตรวจแล้วพบว่างานอีกงานหนึ่ง refactor ไฟล์นี้ไปแล้วใน SEC-029 — ตอนนี้ทุก branch เป็นเมธอดที่ตั้งชื่อชัดเจนและตัดสินสิทธิ์ผ่าน `PermissionEvaluator`/`OrgScopeResolver` ความเปราะที่ INT-00 กังวล (if-chain ของเงื่อนไข inline) ไม่มีอยู่แล้ว · การเพิ่ม branch ที่ 6 ตามรูปแบบเดิมอ่านง่ายกว่าการสร้าง abstraction ให้ผู้ใช้ 6 ราย |
| 2 | หน่วยงานในฟอร์มมาจาก `/api/saraban/documents/options` | ไม่ใช้ `/admin/departments` | endpoint นั้นต้องมีสิทธิ์ `manage_departments` — ผู้ที่สร้างหนังสือได้ไม่ควรต้องถือสิทธิ์จัดการโครงสร้างองค์กรเพียงเพื่อให้ฟอร์มใช้งานได้ |
| 3 | เส้นทางแก้ไขใช้ `POST` ไม่ใช่ `PUT` | ตั้งใจ | ฟอร์มส่ง `multipart/form-data` พร้อมไฟล์ ซึ่ง PHP ไม่ parse ให้กับ `PUT` — เป็นข้อจำกัดจริงของ runtime ไม่ใช่ความชอบ |
| 4 | หน้ารายละเอียดใช้ component เดียวกับหน้าแก้ไข | ตั้งใจ | สถานะ `readOnly` มาจากข้อมูล (`status !== 'draft'`) การแยกเป็นสองหน้าจะทำให้ต้องดูแลฟอร์มสองชุดที่ต้องเหมือนกัน |

---

## CHANGED

**ไม่มีการเปลี่ยนพฤติกรรมของโมดูลเดิม** — ไม่แตะ controller, service, หน้าจอ
หรือ schema ของ leave / attendance / overtime / notification / reporting

แก้ไฟล์เดิม 6 จุด ทั้งหมดเป็นการเพิ่มหรือปรับ fixture ของเทสต์:

| ไฟล์ | การแก้ |
|---|---|
| `config/module_registry.php` | ผูก provider + policy + audit events ของสารบรรณ |
| `routes/api.php` | เพิ่มกลุ่ม `saraban` 6 เส้นทาง |
| `app/Services/SecureFileAccessService.php` | เพิ่ม branch ไฟล์ของหนังสือ (ดูการตัดสินใจข้อ 1) |
| `database/seeders/MenuSeeder.php` | เพิ่มกลุ่มเมนูงานสารบรรณ |
| `frontend/src/router/index.js` | เพิ่ม 3 route |
| `tests/Feature/MenuIntegrityTest.php` | ขึ้นทะเบียน `/saraban/documents/:id` ว่าเข้าจากทะเบียน ไม่ใช่จากเมนู |

### ข้อบังคับจาก INT-02 ที่ถูกปลดล็อกอย่างถูกวิธี

`SarabanModuleRegistrationTest::test_saraban_declares_no_menu_until_its_screens_exist`
ถูกตั้งไว้ตั้งแต่ INT-02 เพื่อบังคับว่า **เมนูต้องมาพร้อมหน้าจอ** ไม่ใช่มาก่อนแล้วกดไป 404

ตอนนี้หน้าจอมีจริงแล้ว จึงแทนที่ด้วยข้อบังคับที่เข้มขึ้นสองข้อ:
- `test_every_saraban_menu_is_gated_by_a_saraban_permission` — ทุกเมนูต้องผูกสิทธิ์
  และสิทธิ์นั้นต้องอยู่ใน namespace ของสารบรรณ
- `test_a_user_without_saraban_permissions_does_not_see_the_menu`

### เทสต์เดิมที่ต้องแก้ 1 จุด

`SarabanDataModelTest` สร้างประเภทหนังสือ `code = 'external'` ซึ่งชนกับข้อมูลอ้างอิง
ที่ migration ของ INT-05 ลงไว้ → เปลี่ยน fixture เป็นรหัสเฉพาะของเทสต์
(**จับได้จาก regression ไม่ใช่จากการเดา** — รอบแรกแดง 5 กรณี)

---

## FILES

**เพิ่มใหม่ (11):**

```
backend/app/Policies/Saraban/DocumentPolicy.php
backend/app/Providers/Modules/SarabanModuleServiceProvider.php
backend/app/Services/Saraban/DocumentService.php
backend/app/Http/Controllers/Saraban/DocumentController.php
backend/app/Http/Requests/Saraban/StoreDocumentRequest.php
backend/app/Http/Requests/Saraban/UpdateDocumentRequest.php
backend/database/migrations/2026_09_04_100000_add_submission_and_seed_saraban_document_types.php
backend/database/migrations/2026_09_04_110000_add_saraban_menus.php
backend/tests/Feature/SarabanDocumentCoreTest.php
frontend/src/views/saraban/DocumentsView.vue
frontend/src/views/saraban/DocumentFormView.vue
frontend/tests/sarabanDocumentsView.test.js
```

---

## MIGRATIONS

| Migration | ประเด็นความปลอดภัย |
|---|---|
| `2026_09_04_100000_add_submission_and_seed_saraban_document_types` | เพิ่มคอลัมน์ `submitted_at` แบบ nullable + ลงข้อมูลอ้างอิงด้วย `firstOrCreate` (รันซ้ำได้ ไม่ทับค่าที่ผู้ดูแลแก้เอง) · `submitted_at` แยกจาก `updated_at` เพราะเป็นหลักฐานว่าหนังสือเข้าสู่กระบวนการเมื่อใด ซึ่งต่างจากเวลาที่แถวถูกแก้ล่าสุด |
| `2026_09_04_110000_add_saraban_menus` | เรียก `MenuSeeder` ซึ่งเป็นแหล่งความจริงเดียวของเมนู — ระบบที่ติดตั้งไปแล้วจะไม่เห็นเมนูใหม่เลยถ้าไม่มี migration นี้ (เหตุผลเดียวกับ `2026_08_28_090000`) · seeder เป็น idempotent |

ทั้งสองตัวเป็น **additive** และรันจากฐานว่างได้ (ยืนยันผ่าน `RefreshDatabase` ทุกเทสต์)

---

## TESTS

`SarabanDocumentCoreTest` — **11 กรณี / 40 assertions**

| กลุ่ม | ครอบคลุม |
|---|---|
| สิทธิ์ | ผู้ไม่มีสิทธิ์ถูกปฏิเสธทุก endpoint · สิทธิ์ `view` ไม่ได้แปลว่าสร้างได้ · **เดา id ของหน่วยงานอื่นแล้วเปิดไม่ได้ (IDOR)** · รายการถูกจำกัดตามขอบเขต |
| เวอร์ชัน | ไฟล์แรกเป็นต้นฉบับเลข 1 · **แนบไฟล์ใหม่แล้วต้นฉบับไม่ถูกแตะ (แฮชและ path เดิม)** |
| วงจรชีวิต | เสนอเรื่องโดยไม่มีไฟล์ไม่ได้ · **เสนอแล้วแก้ไม่ได้** · การเสนอถูกบันทึกใน audit กลาง |
| ไฟล์ | **ไฟล์ของหนังสือเปิดได้เฉพาะคนที่เปิดหนังสือฉบับนั้นได้** |
| โมดูล | ปิดโมดูลแล้วทุก route คืน 404 |

`sarabanDocumentsView.test.js` — **6 กรณี** (UI QA · ดูหัวข้อถัดไป)
`SarabanModuleRegistrationTest` — เปลี่ยนจากข้อบังคับ "ยังไม่มีเมนู" เป็น "เมนูต้องผูกสิทธิ์"

---

## SECURITY

| ประเด็น | ผล |
|---|---|
| Default DENY | ✅ ผู้ไม่มีสิทธิ์ถูกปฏิเสธทั้ง 3 endpoint หลัก (มีเทสต์) |
| แยกสิทธิ์ตามการกระทำ | ✅ `view` / `create` / `update` / `submit` แยกกันจริง — สิทธิ์อ่านไม่ได้แถมสิทธิ์เขียน |
| IDOR | ✅ object-level ผ่าน `DocumentPolicy` + ขอบเขตจาก `OrgScopeResolver` |
| ไฟล์รั่ว | ✅ ไฟล์เสิร์ฟผ่าน `SecureFileController` เดิม และตัดสินด้วย `DocumentPolicy` ตัวเดียวกับ API — ไม่มีทางที่ไฟล์จะเปิดได้กว้างกว่าตัวหนังสือ |
| ไฟล์อันตราย | ✅ ผ่านด่านตรวจไวรัสของ INT-04 อัตโนมัติ เพราะใช้ allowlist กลาง |
| แก้ของที่เสนอไปแล้ว | ✅ ปฏิเสธที่ policy ฝั่ง server ไม่ใช่แค่ซ่อนปุ่ม |
| หน้าเว็บเป็น security boundary? | ❌ ไม่ — router ไม่ผูก permission ตามแนวทางเดิมของโครงการ ทุก endpoint ตรวจซ้ำที่ server |
| ด่านสัญญา | ✅ `SarabanCoreContractTest` เขียว — ไม่มี Core ซ้ำ, ไม่มี `department_id`, ไม่มี `now()` ตรง ๆ |

---

## REGRESSION

| รอบ | ผล |
|---|---|
| Baseline INT-04 | 764 passed · 8 skipped · 0 failed |
| รอบแรกหลัง INT-05 | ⚠️ **5 failed** — fixture ของ `SarabanDataModelTest` ชนกับข้อมูลอ้างอิงที่ migration ลงไว้ |
| **หลังแก้ fixture** | ✅ **776 passed · 8 skipped · 0 failed** · 2807 assertions · 80.52s · exit 0 |
| Frontend | ✅ **70 passed** (7 ไฟล์) |
| Pint (`app tests database config routes`) | ✅ 516 files |
| ESLint · vue-tsc | ✅ ผ่านทั้งคู่ |

**ขอบเขต:** รันเต็มชุดทั้งสองฝั่ง · จุดเสี่ยงคือการแตะ `SecureFileAccessService`
ซึ่งกระทบไฟล์ของทุกโมดูล — `LeaveEvidenceAccessTest`, `AttendanceImageSecurityTest`,
`SecureFileAuthorizationTest` เขียวทั้งหมด

---

## UI-QA

**ไม่ได้แตะ shell, theme, UI kit หรือหน้าจอของโมดูลเดิมแม้แต่บรรทัดเดียว**

| ข้อบังคับ SoT §2 | ผลตรวจ |
|---|---|
| ใช้ App Shell / Sidebar เดิม | ✅ หน้าจอใหม่อยู่ใน `App.vue` เดิม เมนูมาจาก `/api/user/menus` |
| ใช้ UI kit เดิม | ✅ `AppButton`, `AppInput`, `AppSelect`, `AppTextarea`, `AppBadge`, `AppPagination`, `EmptyState`, `ErrorState`, `LoadingState` — **ไม่สร้าง component ใหม่เลย** |
| Theme / tokens เดิม | ✅ เทสต์ตรวจซอร์สของหน้าจอสารบรรณว่าใช้เฉพาะ `#800000` / `#600000` ที่ประกาศไว้ใน `style.css` |
| Layout convention เดิม | ✅ `max-w-7xl mx-auto space-y-6` · การ์ด `rounded-2xl shadow-sm` · ตารางครอบ `overflow-x-auto` (มีเทสต์) |
| Toast เดิม | ✅ ใช้ `alertStore` ไม่สร้างตัวที่สอง |
| ภาษาไทยทั้งหมด | ✅ รวม `aria-label` ของตัวกรอง |
| ไม่ hard-code สิทธิ์ในหน้าเว็บ | ✅ ใช้ `v-can` เดิมเพื่อซ่อนปุ่ม (เป็น UX ไม่ใช่ด่านความปลอดภัย) |

**ข้อผิดพลาดที่เทสต์ UI จับได้ระหว่างทาง:** ใช้ `type="table"`/`type="form"` กับ
`LoadingState` ซึ่งไม่อยู่ในชุดค่าที่ component รับ (`skeleton-table` / `skeleton-form`) —
เป็นตัวอย่างว่าการ mount หน้าจอจริงในเทสต์จับของที่ตามองไม่เห็นได้

> **ข้อจำกัดที่บอกตรง ๆ:** ยังไม่ได้เปิดเบราว์เซอร์ตรวจด้วยตา เพราะการเข้าสู่ระบบต้อง
> กรอกรหัสผ่านซึ่งผมไม่กรอกให้ · ผู้ดูแลเข้า **http://localhost:8001 → เมนู "งานสารบรรณ"**
> ได้หลังรัน `php artisan migrate` และ `npm run build`

---

## EVIDENCE

| หลักฐาน | ที่มา / ผล |
|---|---|
| ต้นฉบับไม่ถูกเขียนทับ | `test_attaching_a_new_file_creates_a_new_version_and_leaves_the_original_untouched` — เทียบแฮชและ path ของต้นฉบับก่อน/หลัง |
| IDOR ปิด | `test_a_document_from_another_unit_cannot_be_opened_by_guessing_its_id` |
| ไฟล์ไม่รั่ว | `test_document_files_are_only_served_to_people_who_may_read_the_document` |
| เสนอแล้วแก้ไม่ได้ | `test_a_submitted_document_can_no_longer_be_edited` (ยืนยันว่าค่าเดิมไม่เปลี่ยนจริง) |
| audit กลาง | `test_submitting_is_recorded_in_the_central_audit_log` |
| ปิดโมดูลได้ผล | `test_turning_the_module_off_closes_every_saraban_route` |
| UI ใช้ของเดิม | `sarabanDocumentsView.test.js` 6 passed (รวมการตรวจชุดสีจากซอร์ส) |
| เทสต์เช็คพอยต์ | `--filter=SarabanDocumentCoreTest` → **11 passed (40 assertions)** |
| Regression | backend **776 passed / 0 failed** · frontend **70 passed** |
| ด่านคุณภาพ | Pint 516 files ✅ · ESLint ✅ · vue-tsc ✅ |

---

## RISKS

| รหัส | ความเสี่ยง | ระดับ | สถานะ |
|---|---|---|---|
| ~~RISK-04~~ | `SecureFileAccessService` เป็น if-chain ที่เปราะ | — | ✅ **ปิด** — SEC-029 ของอีกงานหนึ่งแก้ต้นเหตุไปแล้ว การเพิ่ม branch ตามรูปแบบเดิมจึงเหมาะสมกว่าการสร้าง abstraction |
| RISK-18 **(ใหม่)** | ยังไม่มีการผูกหนังสือเข้ากับ workflow engine — สถานะจึงมีแค่ `draft` / `submitted` | ต่ำ | ตั้งใจ · การเดินสายงานเป็นงานของ INT-08 ตาม roadmap · `submit` ถูกออกแบบให้เป็นจุดที่ workflow เข้ามาต่อได้โดยไม่ต้องรื้อ |
| RISK-19 **(ใหม่)** | หน้าจอยังไม่มีตัวแสดง PDF — ผู้ใช้เห็นรายการเวอร์ชันแต่ยังเปิดดูไฟล์ในหน้าไม่ได้ | ต่ำ | ตัวแสดง PDF เป็นงานของ INT-10 (PDF Workspace) ตามที่ `UI-COMPATIBILITY-MATRIX.md` §7.2 ระบุไว้ · ไฟล์ยังดาวน์โหลดผ่านเส้นทางที่ตรวจสิทธิ์ได้ |
| RISK-20 **(ใหม่)** | ผู้ใช้เลือกหน่วยงานเจ้าของเรื่องได้ทุกหน่วยงานที่ยังใช้งานอยู่ | **กลาง** | ควรจำกัดให้เลือกได้เฉพาะหน่วยงานในขอบเขตของตน — **ตั้งใจปล่อยไว้เพราะยังไม่มีกติกาที่ยืนยันแล้วว่าใครยื่นแทนหน่วยงานใดได้** (เกี่ยวกับ Authority Matrix ของ INT-08) · ผลกระทบจำกัดเพราะการ *เห็น* ยังถูกจำกัดด้วยขอบเขตเสมอ |

**ไม่มี blocker สำหรับ INT-06**

---

## NEXT

**→ INT-06 — Numbering Engine**

1. State machine ของเลขหนังสือครบ 7 สถานะตาม SoT §9
   (AVAILABLE → RESERVED → ALLOCATED → ISSUED · RELEASED / CANCELLED / VOID)
2. **ISSUED ห้าม reuse** และต้อง concurrency-safe — ห้าม `SELECT MAX()+1` แบบไม่มี lock
   (แบบอย่างที่ใช้ได้คือ `LeaveDocumentNumberService` ซึ่งใช้ `lockForUpdate` อยู่แล้ว)
3. ปี พ.ศ. + annual reset + ชุดเลขแยกตามหน่วยงานและประเภทหนังสือ
4. **ต้องมีเทสต์ concurrency จริง** ไม่ใช่เทสต์ลำดับเดียว — เป็นข้อบังคับของ SoT §9
5. ผูกเลขเข้ากับหนังสือตอน `submit` และแสดงในทะเบียน
