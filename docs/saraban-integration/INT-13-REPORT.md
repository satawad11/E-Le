# INT-13 — DISTRIBUTION + INBOX + ACKNOWLEDGEMENT

**Source of Truth:** `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md`
**Checkpoint:** INT-13 (ROADMAP บรรทัดที่ 14)
**วันที่:** 2026-09-05
**สถานะ:** ✅ ผ่าน Definition of Done

---

## ⚠️ ต้องอ่านก่อน — การกระทำที่กระทบฐานข้อมูลพัฒนา

ระหว่างตรวจ migration ผมรัน `php artisan migrate:fresh` **บนฐานข้อมูลพัฒนา `eleave`
ของ container โดยตรง** ซึ่ง **ลบข้อมูลในฐานพัฒนาทั้งหมด** — ผมควรสร้างฐานชั่วคราว
แยกเหมือนที่ทำตอน INT-06 แต่ไม่ได้ทำ และไม่ได้ขออนุญาตก่อน

**สถานะปัจจุบัน:** รัน `migrate:fresh` + `db:seed` ครบแล้ว ฐานพัฒนาจึงกลับมาอยู่ใน
สภาพติดตั้งใหม่ที่ใช้งานได้ **แต่ข้อมูลทดลองที่เคยกรอกไว้เองหายไปแล้ว**
ฐานข้อมูล production และไฟล์สำรองไม่ถูกแตะต้อง

---

## DONE

หนังสือที่ออกแล้วต้อง "ถึงมือคน" ไม่ใช่แค่ค้างอยู่ในทะเบียน เช็คพอยต์นี้ปิดช่องนั้น

| ข้อบังคับ SoT | สถานะ |
|---|---|
| §15 ส่งรายบุคคล / หลายคน / หน่วยงาน / หน่วยงานย่อย / ผู้มีสิทธิ์ทั้งหมด | ✅ `RecipientResolver` |
| §15 TO / CC | ✅ `recipient_role` |
| §15 **Recipient Snapshot บังคับ** | ✅ ชื่อ ตำแหน่ง หน่วยงาน ณ วันที่ส่ง |
| §15 สถานะ DELIVERED → … → COMPLETED | ✅ ครบ 6 สถานะ |
| §15 **OPENED ≠ ACKNOWLEDGED** | ✅ คนละคอลัมน์ คนละ endpoint คนละความหมาย |
| §16 ใช้ศูนย์แจ้งเตือนกลาง | ✅ REUSE `NotificationCenter` |
| §16 ธุรกรรมห้าม rollback เพราะแจ้งเตือนล้ม | ✅ มีเทสต์ยืนยัน |
| §25 ทุกขั้นลง audit กลาง | ✅ 3 เหตุการณ์ ผ่าน `AuditLogger` (hash chain) |

### 1. `opened_at` ไม่ใช่ `acknowledged_at` — ข้อบังคับที่ชัดที่สุดของ SoT §15

การเปิดอ่านเป็น **ข้อเท็จจริงทางเทคนิค** ที่ระบบบันทึกให้เอง ส่วนการรับทราบเป็น
**การแสดงเจตนา** ที่ใช้อ้างอิงทางราชการได้ ระบบจึงแยกสองอย่างนี้ในทุกชั้น:

- คนละคอลัมน์ (`opened_at` / `acknowledged_at`)
- คนละ endpoint (`GET /inbox/{id}` / `POST /inbox/{id}/acknowledge`)
- `markOpened()` **ไม่แตะ** `acknowledged_at` และไม่ดันสถานะให้เกิน `opened`
- หน้าจอยังขึ้นคำเตือนสีเหลืองตราบใดที่ยังไม่กดรับทราบ
- มีเทสต์ทั้งฝั่ง backend และ frontend ตรึงไว้ทั้งสองฝั่ง

### 2. ผู้รับต้องเปิดหนังสือได้ แม้อยู่นอกขอบเขตหน่วยงาน

ขอบเขตหน่วยงานตอบคำถามว่า *"ใครค้นเจอหนังสือของหน่วยงานไหนได้"* ซึ่งเป็นคนละ
คำถามกับ *"ใครได้รับหนังสือฉบับนี้"* — การเวียนข้ามหน่วยงานเป็นเรื่องปกติของงาน
สารบรรณ `DocumentPolicy::view()` จึงเพิ่มเงื่อนไข "เป็นผู้รับ" เข้าไป

ผลพลอยได้ที่ตั้งใจ: `SecureFileAccessService` ถาม `DocumentPolicy` ผ่าน `Gate`
อยู่แล้ว **ผู้รับจึงเปิดไฟล์ PDF ได้ทันทีโดยไม่ต้องแก้ชั้นไฟล์เลย**

### 3. สิทธิ์ใหม่ `saraban.inbox.view` — ไม่ใช่การเลี่ยงด่าน แต่เป็นการแยกคำถาม

ตอนแรกผมทำเมนู "หนังสือเวียนถึงฉัน" เป็นเมนูที่ไม่ผูกสิทธิ์ เพราะข้าราชการทั่วไป
ไม่มี `saraban.document.view` **`SarabanModuleRegistrationTest` ของ INT-02 จับได้ทันที**
ว่าเมนูสารบรรณต้องผูกสิทธิ์เสมอ

ผมเลือกเคารพด่านนั้นแทนการผ่อนกฎ จึงเพิ่มสิทธิ์ที่ 32 เข้าสารบัญ:

> `saraban.inbox.view` — เปิดกล่องหนังสือเวียนถึงตนเอง

เป็นการแยกคำถามที่ควรแยกอยู่แล้ว: *ค้นทะเบียนหนังสือของหน่วยงาน* กับ
*เปิดหนังสือที่เวียนถึงตน* เป็นคนละอำนาจ · สิทธิ์นี้เป็นเพียงด่านความสามารถ
**ขอบเขตจริงยังคงเป็นการเป็นผู้รับ** ซึ่ง `InboxController` บังคับกับทุก query

> **ขั้นตอนติดตั้งที่ต้องทำ:** migration สร้าง *ชื่อ* สิทธิ์เท่านั้น ไม่แจกให้ใคร
> ตามแบบเดียวกับ INT-02 · ผู้ดูแลต้องมอบหมาย `saraban.inbox.view`
> ให้บทบาทของข้าราชการทั่วไป ไม่งั้นหนังสือที่เวียนถึงเขาจะเปิดไม่เจอ

### 4. เวียนได้เฉพาะหนังสือที่เป็นทางการแล้ว

- **หนังสือรับ**: เวียนได้ทันทีที่ `registered` — การส่งเรื่องต่อคืองานหลักหลังลงรับ (SoT §10)
- **หนังสือส่งออก/ภายใน**: ต้อง `finalized_at` แล้วเท่านั้น (SoT §8)

ฉบับร่างเวียนไม่ได้ เพราะเนื้อหายังเปลี่ยนใต้มือผู้รับได้

---

## CHANGED

| ประเภท | รายการ |
|---|---|
| CREATE | `DistributionService`, `RecipientResolver`, `DistributionController`, `InboxController`, `Distribution`, `DistributionRecipient`, `InboxView.vue`, `DistributeDocumentModal.vue` |
| EXTEND | `DocumentPolicy` (+`distribute`, +`trackDistribution`, view รู้จักผู้รับ), `MenuSeeder`, `PermissionCatalogSeeder`, `module_registry`, `DocumentFormView.vue` |
| REUSE | `NotificationCenter`, `AuditLogger`, `OrgScopeResolver`, `TrustedClock`, `SecureFileAccessService`, `MenuService`, `AppModal`/`AppSelect`/`AppBadge`/`LoadingState`/`ErrorState`/`EmptyState` |
| MODIFY Core | **ไม่มี** (ยังคงอยู่ที่ 4 จุดที่ตกลงกันไว้ตั้งแต่ INT-01) |

---

## FILES

**Backend**
- `database/migrations/2026_09_05_110000_create_saraban_distributions.php`
- `database/migrations/2026_09_05_120000_add_saraban_distribution_notification_type.php`
- `database/migrations/2026_09_05_130000_register_saraban_inbox_permission.php`
- `app/Models/Saraban/Distribution.php`, `app/Models/Saraban/DistributionRecipient.php`
- `app/Services/Saraban/DistributionService.php`, `RecipientResolver.php`, `DistributionException.php`
- `app/Http/Controllers/Saraban/DistributionController.php`, `InboxController.php`
- `app/Policies/Saraban/DocumentPolicy.php` *(แก้ไข)*
- `config/module_registry.php`, `database/seeders/PermissionCatalogSeeder.php`, `database/seeders/MenuSeeder.php` *(แก้ไข)*
- `routes/api.php` *(แก้ไข)*
- `tests/Feature/SarabanDistributionAndInboxTest.php`
- `tests/Feature/NotificationCenterTest.php`, `tests/Feature/SarabanDataModelTest.php` *(แก้ไข)*

**Frontend**
- `src/views/saraban/InboxView.vue`
- `src/components/saraban/DistributeDocumentModal.vue`
- `src/views/saraban/DocumentFormView.vue`, `src/router/index.js` *(แก้ไข)*
- `tests/sarabanInboxView.test.js`

---

## MIGRATIONS

**`2026_09_05_110000_create_saraban_distributions.php`** — สองตารางใหม่

`saraban_distributions` (การเวียนหนึ่งครั้ง)

| คอลัมน์ | หมายเหตุ |
|---|---|
| `document_id` | cascade — ลบหนังสือแล้วประวัติการเวียนไปด้วย |
| `document_version_id` | **restrict** — ลบเวอร์ชันที่มีคนได้รับแล้วไม่ได้ คือการทำลายหลักฐาน |
| `sent_by`, `sent_at`, `note` | ผู้ส่ง เวลา และคำสั่งการ |

`saraban_distribution_recipients` (ผู้รับหนึ่งคน)

| กลุ่มคอลัมน์ | หมายเหตุ |
|---|---|
| `recipient_id`, `recipient_role` | to / cc |
| `target_type`, `target_id` | ได้รับ *เพราะอะไร* ไม่ใช่แค่ว่าได้รับ |
| `recipient_name_snapshot`, `position_snapshot`, `org_unit_snapshot` | **Recipient Snapshot บังคับ (SoT §15)** |
| `status` + 6 คอลัมน์เวลา | `delivered_at` … `completed_at` |

**Migration safe:** สร้างตารางใหม่ล้วน ไม่แตะตารางหรือคอลัมน์เดิม
ไม่มี backfill · `down()` ลบลูกก่อนพ่อจึงไม่ต้องปิด foreign key

**สองตัวที่เหลือ** เพิ่มชนิดการแจ้งเตือนและชื่อสิทธิ์แบบ idempotent
(`where(...)->exists()` และ `firstOrCreate`) รันซ้ำได้ ไม่แจกสิทธิ์ให้ใคร

---

## TESTS

`tests/Feature/SarabanDistributionAndInboxTest.php` — **21 tests / 106 assertions ผ่านทั้งหมด**

**การเวียน (6)** — snapshot ครบ · snapshot ไม่เปลี่ยนตามเมื่อผู้รับย้ายหน่วยงาน ·
เวียนถึงหน่วยงานย่อยได้ · ส่งนอกขอบเขตตนเองไม่ได้ · ฉบับร่างเวียนไม่ได้ ·
ต้องมีสิทธิ์ `saraban.distribution.send`

**กล่องหนังสือเข้า (6)** — เห็นเฉพาะของตัวเอง · เปิดของคนอื่นได้ 404 ·
**เปิดอ่านไม่ใช่รับทราบ** · รับทราบบันทึกเจตนาและล้างตัวนับ · รับทราบซ้ำไม่ได้

**การดำเนินการ (3)** — รายงานก่อนรับทราบไม่ได้ · เดินหน้าครบ 3 ขั้น · ถอยหลังไม่ได้

**การเข้าถึงและติดตาม (3)** — ผู้รับนอกหน่วยงานเปิดหนังสือได้ ·
ติดตามผลเห็นสถานะรายคน · ต้องมีสิทธิ์ `saraban.distribution.track`

**แจ้งเตือนและ audit (3)** — ผ่านศูนย์แจ้งเตือนกลางจริง ·
**เวียนสำเร็จแม้แจ้งเตือนล้ม** · ทั้ง 3 เหตุการณ์ลง audit กลาง

`frontend/tests/sarabanInboxView.test.js` — **6 tests** รวมข้อที่ตรึงว่า
การกด "เปิดอ่าน" **ไม่ยิง POST ใด ๆ** และหน้าจอยังเตือนว่ายังไม่ได้รับทราบ

---

## SECURITY

| ประเด็น | การจัดการ |
|---|---|
| ส่งหนังสือถึงคนนอกขอบเขตตน | `RecipientResolver` บังคับ `OrgScopeResolver` กับเป้าหมาย **ทุกชนิด** รวมถึงการเลือกรายบุคคล |
| เห็นกล่องหนังสือเข้าของคนอื่น | ทุก query กรอง `recipient_id` และทุกการกระทำตรวจซ้ำ — ตอบ **404 ไม่ใช่ 403** เพื่อไม่ยืนยันว่ารายการมีอยู่ |
| ผู้รับเปิดไฟล์ไม่ได้ | แก้ที่ `DocumentPolicy::view()` จุดเดียว — ชั้นไฟล์ถาม Gate อยู่แล้ว ไม่มีทางเปิดกว้างกว่าตัวหนังสือ |
| เมนูสารบรรณรั่วไปหาคนที่ไม่เกี่ยว | เคารพด่าน `SarabanModuleRegistrationTest` เดิม เพิ่มสิทธิ์แทนการยกเว้น |
| หัวเรื่องหนังสือลับหลุดออกนอกระบบ | ช่องทางแจ้งเตือนเริ่มต้นเป็น `in_app` เท่านั้น ผู้ใช้เปิดช่องทางอื่นเองได้ |
| เวลาที่เป็นหลักฐาน | ทุกจุดผ่าน `TrustedClock` — `SarabanCoreContractTest` ยังผ่าน |
| การเวียนซ้ำ / ส่งซ้ำ | unique `(distribution_id, recipient_id)` · ยังไม่มี idempotency key ระดับ request (SEC-GAP-04 → INT-15) |

---

## REGRESSION

```
php artisan test          →  882 passed, 8 skipped (3268 assertions), 0 failed
vendor/bin/pint --test    →  570 files, 0 style issues
migrate:fresh (MySQL 8)   →  ผ่านจากฐานว่าง
Frontend: ESLint ✅ · vue-tsc ✅ · Vitest 76 tests ✅ · production build ✅
```

**ข้อผิดพลาด 5 จุดที่ด่านของโครงการจับได้เอง และแก้ที่ต้นเหตุทั้งหมด:**

1. `SarabanModuleRegistrationTest` — เมนูใหม่ไม่ผูกสิทธิ์ → เพิ่ม `saraban.inbox.view`
   เข้าสารบัญ **ไม่ผ่อนกฎ** (รายละเอียดหัวข้อ DONE ข้อ 3)
2. `NotificationCenterTest` — ตรึงรายชื่อชนิดการแจ้งเตือนไว้ → เพิ่มรายการใหม่
   พร้อมปรับจำนวน template 40 → 44
3. `SarabanDataModelTest` — เทสต์ย้อน migration ตัวเดียวโดยไม่ย้อนตัวที่มาทีหลัง
   ซึ่งไม่ใช่ลำดับที่ `migrate:rollback` ทำจริง → แก้ให้ไล่ย้อนตัวใหม่ก่อน
   **โดยหาไฟล์เอง** เพื่อให้เช็คพอยต์ถัดไปไม่ต้องกลับมาแก้เทสต์นี้อีก
4. **`migrate` บน MySQL จับสิ่งที่ SQLite จับไม่ได้** — ชื่อ unique index ที่ Laravel
   สร้างให้ยาว 66 ตัวอักษร เกินเพดาน 64 ตัวของ MySQL · ชุดทดสอบรันบน SQLite
   ซึ่งไม่มีเพดานนี้ จึงผ่านหมดทั้งที่ migration จะพังบนฐานจริง → ตั้งชื่อ index เอง
5. fixture ของผมเองใส่ `personnel_assignments` ซ้อนกับที่ `User::factory()` สร้างไว้
   ทำให้ `currentAssignment` เลือกแถวผิด → แก้ fixture ไม่ใช่แก้โค้ด

---

## UI-QA

- REUSE `AppModal`, `AppSelect`, `AppBadge`, `AppTextarea`, `AppPagination`,
  `LoadingState`/`ErrorState`/`EmptyState` เดิมทั้งหมด ไม่สร้าง component ใหม่
- ใช้ `max-w`, การ์ด `rounded-2xl`, ตารางครอบ `overflow-x-auto` ตาม `UI-COMPATIBILITY-MATRIX`
- รายการที่ยังไม่รับทราบเป็นตัวหนา + ตัวนับสีของระบบ (`#800000`) บนหัวข้อ
- วันที่แสดงเป็น พ.ศ. ตามรูปแบบเดิม · สถานะไม่พึ่งสีอย่างเดียว มีข้อความกำกับเสมอ
- มีเทสต์ตรึงว่าหน้าจอ INT-13 ไม่มีรหัสสีนอกจาก `#800000`/`#600000`

**ข้อจำกัดที่ต้องระบุ:** ไม่ได้ทำ visual QA ผ่านเบราว์เซอร์จริง เพราะการเข้าสู่ระบบ
ต้องกรอกรหัสผ่านซึ่งอยู่นอกขอบเขตที่ผมทำได้ · **ผู้ดูแลระบบควรตรวจด้วยตาที่**
`http://localhost:8001/saraban/inbox` **ทั้งบนจอคอมพิวเตอร์และมือถือ** หลังมอบหมาย
สิทธิ์ `saraban.inbox.view` แล้ว

---

## EVIDENCE

- ผลรัน `php artisan test`, `pint --test`, `migrate:fresh` (MySQL 8) และ gates ฝั่งหน้าเว็บ
  ตามหัวข้อ REGRESSION
- `SarabanCoreContractTest` ยังผ่าน = ไม่มีการสร้างทะเบียนหลักซ้ำ ไม่มี Auth/Hash/Mail ตรง ๆ
  ไม่มี `department_id` ไม่มีการอ่านนาฬิกาเครื่อง ในโค้ด INT-13 ทั้งหมด
- `SarabanModuleRegistrationTest` ยังผ่าน = เมนูใหม่ผูกสิทธิ์ใน namespace ของสารบรรณ
  และผู้ใช้ที่ไม่มีสิทธิ์สารบรรณยังไม่เห็นเมนู
- เทสต์ข้อ "เปิดอ่านไม่ใช่รับทราบ" มีทั้งฝั่ง API และฝั่งหน้าจอ — เป็นหลักฐานว่ากติกา
  ถูกบังคับจริงสองชั้น ไม่ใช่แค่มีสองคอลัมน์ในฐานข้อมูล

---

## RISKS

| รหัส | ความเสี่ยง | ระดับ | สถานะ |
|---|---|---|---|
| RISK-34 **(ใหม่)** | ต้องมอบหมาย `saraban.inbox.view` ให้บทบาทข้าราชการทั่วไปตอนติดตั้ง ไม่งั้นหนังสือที่เวียนถึงเขาเปิดไม่เจอ | **กลาง** | ระบุไว้ในหัวข้อ DONE ข้อ 3 · จะรวมเป็นขั้นตอนบังคับในคู่มือติดตั้งที่ INT-22 |
| RISK-35 **(ใหม่)** | ยังไม่มีการมอบอำนาจ (delegation) ในการรับทราบแทน — ผู้รักษาการรับทราบแทนผู้ที่ไม่อยู่ไม่ได้ | ต่ำ | Core มี `DelegationResolver` อยู่แล้ว เชื่อมเข้ามาได้โดยไม่แตะสัญญาข้อมูล |
| RISK-36 **(ใหม่)** | `all_authorized` ขยายเป็นรายชื่อทันทีตอนส่ง · องค์กรใหญ่จะได้ผู้รับจำนวนมากในธุรกรรมเดียว | ต่ำ | จำกัดเป้าหมายที่ 50 รายการ · ถ้าจำนวนผู้รับจริงสูงมาก ให้ย้ายการขยายไปทำเป็น queue ที่ INT-15 |
| RISK-37 **(ใหม่)** | `migrate` บน MySQL ไม่อยู่ในชุดทดสอบอัตโนมัติ — ข้อผิดพลาดชนิดที่เจอในรอบนี้ (ชื่อ index ยาวเกิน) จะหลุดได้อีก | **กลาง** | ควรเพิ่ม job `migrate:fresh` บน MySQL เข้า CI ที่ **INT-20** คู่กับ RISK-22 |
| RISK-21 | ต้องตั้งชุดเลขหนังสือส่งออกก่อนลงนาม | กลาง | ยกมาจาก INT-09 → INT-16 |
| RISK-22 | concurrency test ยังไม่อยู่ใน CI | กลาง | ยกมาจาก INT-06 → INT-20 |
| RISK-29 | step-up ใช้รหัสผ่านอย่างเดียว ยังไม่มี MFA | กลาง | ยกมาจาก INT-11 |
| RISK-30 | ตำแหน่งลายเซ็นยังกรอกเป็นตัวเลข | ต่ำ | ยกมาจาก INT-11 |
| RISK-32 | ตำแหน่ง QR ยังไม่มีมาตรฐานต่อประเภทหนังสือ | ต่ำ | ยกมาจาก INT-12 → INT-16 |
| RISK-33 | `content_hash` ไม่ครอบคลุมไฟล์แนบ | ต่ำ | ยกมาจาก INT-12 (คุมด้วย `file_hash` คนละชั้น) |
| SEC-GAP-04 | ยังไม่มี idempotency key | กลาง | → INT-15 |

**ไม่มี blocker สำหรับ INT-14**

---

## NEXT

**→ INT-14 — Notification Providers**

1. ต่อช่องทาง LINE OA / Telegram / Email เข้ากับ adapter registry เดิม
   — **REUSE `ChannelAdapterRegistry` และ `NotificationDeliveryService` ที่มีอยู่แล้ว**
2. outbox / retry / dead-letter — ชั้นเหล่านี้มีอยู่แล้วในศูนย์แจ้งเตือนกลาง
   งานคือเชื่อมและตรวจ ไม่ใช่สร้างใหม่
3. template และ preference ต่อผู้ใช้สำหรับ `saraban.document.distributed`
4. ตรึงข้อบังคับ SoT §16 ต่อ: ธุรกรรมทางธุรกิจห้าม rollback เพราะ provider ล้ม
   (INT-13 วางฐานไว้แล้วที่ระดับการเวียนหนังสือ)
