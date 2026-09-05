# INT-07 — INCOMING REGISTRATION

**Source of Truth:** `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md`
**Checkpoint:** INT-07 (ROADMAP บรรทัดที่ 8)
**วันที่:** 2026-09-04
**Branch:** `chore/remove-saraban-module`
**สถานะ:** ✅ ผ่าน Definition of Done ของเช็คพอยต์นี้

---

## DONE

ทะเบียนรับหนังสือตาม SoT §10 — เป็นจุดที่เครื่องออกเลขของ INT-06 ถูกใช้งานจริงครั้งแรก

| ขั้นตอนตาม SoT §10 | สถานะ |
|---|---|
| validate | ✅ `RegisterIncomingRequest` |
| **duplicate detect** | ✅ ตรวจจาก (เลขต้นทาง + ผู้ส่ง + วันที่ต้นทาง) — เตือนแล้วให้คนตัดสิน |
| register | ✅ สร้างหนังสือสถานะ `registered` |
| number | ✅ ออกเลขทะเบียนรับผ่าน `NumberingService` (จอง→ผูก→ออก) |
| **trusted time** | ✅ `received_at` มาจาก `TrustedClock` ไม่รับจากผู้กรอก |
| receive stamp | ✅ **เป็นข้อมูล** (เลขรับ + วันเวลารับ) — การวางบน PDF เป็นงานของ INT-10 |
| registered version | ✅ ต้นฉบับถูกบันทึกเป็นเวอร์ชันแรก |
| hash | ✅ `file_hash` จาก `DocumentService::addVersion()` |
| audit | ✅ `saraban.incoming.registered` เข้า audit กลาง + โซ่แฮช |

### สิ่งที่สำคัญที่สุดของเช็คพอยต์นี้ — แยกสองชุดข้อมูลออกจากกัน

| ชุดข้อมูล | ฟิลด์ | เจ้าของ |
|---|---|---|
| **หนังสือต้นทาง** | `source_number`, `source_date`, `sender_name` | ผู้ส่งเป็นคนออก — เราไม่ได้ควบคุม |
| **การรับ** | `document_number_id` (เลขทะเบียนรับ), `received_at` | หน่วยงานเราเป็นคนออก |

ถ้าเก็บปนกันจะตอบไม่ได้ว่า "หนังสือ ศธ 04002/1234 ลงวันที่ 1 ก.ย." กับ
"ทะเบียนรับเลข 0001/2569 เมื่อ 4 ก.ย." เป็นเลขคนละชุดกัน ซึ่งเป็นคนละความหมาย
ตามระเบียบงานสารบรรณ และเป็นที่มาของการตรวจสอบย้อนหลังไม่ได้

### การตัดสินใจที่ควรบันทึก

| # | เรื่อง | ตัดสิน | เหตุผล |
|---|---|---|---|
| 1 | **หนังสือซ้ำ** | ตอบ **409 พร้อมรายการที่ซ้ำ** แล้วรับได้เมื่อผู้ใช้ยืนยัน — ไม่ใช่ unique constraint ที่ฐานข้อมูล | การรับหนังสือฉบับเดียวกันซ้ำ **อาจถูกต้องได้จริง** (ผู้ส่งส่งซ้ำ และหน่วยงานเลือกรับทั้งสองฉบับ) การให้ฐานข้อมูลปฏิเสธจะบล็อกงานที่ถูกต้อง · SoT §10 ให้ "ตรวจซ้ำ" ไม่ได้ให้ "ห้ามซ้ำ" |
| 2 | **ไม่รับ `received_at` จากผู้ใช้** | วันเวลารับมาจาก `TrustedClock` เท่านั้น | ถ้ารับค่าจากผู้กรอกได้ ทะเบียนรับจะถูกย้อนวันได้ ซึ่งทำลายคุณค่าของทะเบียนในฐานะหลักฐาน · มีเทสต์ยืนยันว่าค่าที่ส่งมาถูกเพิกเฉย |
| 3 | **ไม่มีเลขต้นทาง = ไม่เดาว่าซ้ำ** | ข้ามการตรวจซ้ำเมื่อ `source_number` ว่าง | หนังสือจากบุคคลทั่วไปมักไม่มีเลขที่ตามระเบียบ — การเดาว่าซ้ำจากชื่อผู้ส่งอย่างเดียวจะเตือนผิดจนคนเลิกอ่านคำเตือน |
| 4 | **ออกเลขครบสามขั้นในคราวเดียว** | จอง → ผูก → ออก ภายใน transaction เดียว | การรับหนังสือเป็นเหตุการณ์ที่เกิดขึ้นแล้วจริง ไม่ใช่การจองเลขไว้ล่วงหน้า · ถ้าขั้นใดล้ม ต้องไม่เหลือเลขที่ออกไปแล้วแต่ไม่มีหนังสือผูกอยู่ |
| 5 | **ชุดเลขทะเบียนรับ seed ได้เลย** | migration สร้าง `incoming-register` ให้ (ไม่มีคำนำหน้า) | เลขทะเบียนรับเป็นเลขลำดับภายใน ไม่มีคำนำหน้าตามหนังสือราชการ จึงไม่ต้องเดาค่าเฉพาะของสถานศึกษา (ต่างจาก RISK-21 ของเลขหนังสือส่ง) |

---

## CHANGED

**ไม่มีการเปลี่ยนพฤติกรรมของโมดูลเดิม** — ไม่แตะ leave / attendance / overtime /
notification / reporting และไม่ต้องแก้เทสต์เดิมแม้แต่ข้อเดียวในเช็คพอยต์นี้

แก้ไฟล์ของสารบรรณเอง 3 จุด: `routes/api.php` (+4 เส้นทาง),
`MenuSeeder` (+2 เมนู), `Document` (+สถานะ `registered`)

---

## FILES

**เพิ่มใหม่ (7):**

```
backend/database/migrations/2026_09_04_130000_create_saraban_incoming_registrations.php
backend/app/Models/Saraban/IncomingRegistration.php
backend/app/Services/Saraban/IncomingRegistrationService.php
backend/app/Http/Requests/Saraban/RegisterIncomingRequest.php
backend/app/Http/Controllers/Saraban/IncomingRegistrationController.php
backend/tests/Feature/SarabanIncomingRegistrationTest.php
frontend/src/views/saraban/IncomingView.vue
frontend/src/views/saraban/IncomingRegisterView.vue
```

---

## MIGRATIONS

`2026_09_04_130000_create_saraban_incoming_registrations.php`

| ประเด็น | ผล |
|---|---|
| Backward compatible | ✅ ตารางใหม่ล้วน + seed ชุดเลขด้วย `firstOrCreate` (รันซ้ำได้) |
| ดัชนีตรวจซ้ำ | `(source_number, sender_name, source_date)` เป็น **index ไม่ใช่ unique** โดยตั้งใจ (ดูการตัดสินใจข้อ 1) |
| `document_id` unique | หนังสือหนึ่งฉบับลงทะเบียนรับได้ครั้งเดียว |
| `down()` | ถอนตารางและชุดเลขที่ seed ไว้ได้สะอาด |

---

## TESTS

`SarabanIncomingRegistrationTest` — **11 กรณี / 37 assertions**

| กลุ่ม | ครอบคลุม |
|---|---|
| แยกข้อมูล | **เลขต้นทางกับเลขทะเบียนรับอยู่คนละช่องและคนละค่า** · เลขรับอยู่ในสถานะ ISSUED |
| เวลาอ้างอิง | **ค่า `received_at` ที่ผู้ใช้ส่งมาถูกเพิกเฉย** ใช้เวลาของระบบเท่านั้น |
| ตรวจซ้ำ | รับซ้ำถูกเตือนด้วย 409 และ **ยังไม่บันทึกจนกว่าจะยืนยัน** · ยืนยันแล้วรับได้ · ไม่มีเลขต้นทางไม่เดาว่าซ้ำ · เรียกตรวจซ้ำก่อนส่งฟอร์มได้ |
| เลขทะเบียนรับ | แต่ละฉบับได้เลขของตัวเอง เดินเป็นลำดับ 1, 2 |
| ไฟล์ | ต้นฉบับถูกเก็บเป็นเวอร์ชันแรกและอยู่บน private disk |
| สิทธิ์ | ต้องมี `saraban.incoming.register` จึงลงทะเบียนได้ · คนนอกอ่านทะเบียนไม่ได้ |
| ประวัติ | เข้า audit กลาง |

---

## SECURITY

| ประเด็น | ผล |
|---|---|
| แยกสิทธิ์อ่าน/ลงทะเบียน | ✅ `saraban.document.view` อ่านได้ แต่ต้องมี `saraban.incoming.register` จึงลงทะเบียนได้ |
| ขอบเขตการมองเห็น | ✅ ทะเบียนรับถูกกรองด้วย `OrgScopeResolver` เหมือนทะเบียนหนังสือ |
| ย้อนวันที่รับ | ✅ เป็นไปไม่ได้ — ไม่รับค่าจากคำขอเลย |
| ไฟล์ที่รับเข้ามา | ✅ ผ่านด่านตรวจไวรัสของ INT-04 อัตโนมัติ (ใช้ allowlist กลาง) |
| ประวัติแก้ย้อนหลัง | ✅ เข้าโซ่แฮชของ INT-04 |
| ด่านสัญญา | ✅ `SarabanCoreContractTest` เขียว |

---

## REGRESSION

| รอบ | ผล |
|---|---|
| Baseline INT-06 | 790 passed · 8 skipped · 0 failed |
| **หลัง INT-07** | ✅ **801 passed · 8 skipped · 0 failed** · 2881 assertions · exit 0 |
| Frontend | ✅ **70 passed** (7 ไฟล์) |
| Pint | ✅ 529 files |
| ESLint · vue-tsc | ✅ ผ่านทั้งคู่ |

**ผ่านตั้งแต่รอบแรก ไม่มีเทสต์เดิมแดง** — ต่างจาก INT-05/INT-06 ที่ต้องแก้ fixture

---

## UI-QA

หน้าจอใหม่ 2 หน้า ใช้ UI kit เดิมทั้งหมด **ไม่สร้าง component ใหม่**

| หน้าจอ | ใช้ของเดิม |
|---|---|
| `IncomingView` (ทะเบียนรับ) | `AppButton`, `AppInput`, `AppPagination`, `EmptyState`, `ErrorState`, `LoadingState` + layout convention เดิม |
| `IncomingRegisterView` (ลงทะเบียนรับ) | `AppButton`, `AppInput`, `AppSelect`, `AppTextarea`, `ErrorState`, `LoadingState` + `alertStore` เดิม |

**จุดที่ออกแบบ UX ให้ตรงกับกติกาธุรกิจ:** เมื่อ API ตอบ 409 หน้าจอ **ไม่แสดงเป็น error**
แต่แสดงกล่องเตือนสีเหลืองพร้อมรายการหนังสือที่อาจซ้ำ และช่องติ๊กยืนยัน — เพราะการพบ
หนังสือซ้ำไม่ใช่ความผิดพลาด แต่เป็นการขอให้คนตัดสิน

ตารางทะเบียนรับแสดง **เลขทะเบียนรับกับเลขที่ต้นทางเป็นคนละคอลัมน์** เพื่อให้ความแตกต่าง
ของสองเลขนี้ชัดตั้งแต่หน้าจอ ไม่ใช่ชัดแค่ในฐานข้อมูล

> ยังไม่ได้เปิดเบราว์เซอร์ตรวจด้วยตาด้วยเหตุผลเดิม (ต้องกรอกรหัสผ่านเข้าระบบ)

---

## EVIDENCE

| หลักฐาน | ที่มา / ผล |
|---|---|
| แยกเลขต้นทาง/เลขรับ | `test_the_source_number_and_the_register_number_are_kept_apart` |
| เวลารับปลอมไม่ได้ | `test_the_received_time_comes_from_the_trusted_clock_not_from_the_request` (ส่ง `received_at=2020-01-01` แล้วได้ค่าของระบบ) |
| ตรวจซ้ำแล้วยังไม่บันทึก | `test_registering_the_same_source_document_twice_is_flagged_before_it_is_accepted` |
| ยืนยันแล้วรับซ้ำได้ | `test_a_confirmed_duplicate_can_still_be_registered` |
| เลขรับเดินเป็นลำดับ | `test_each_registration_gets_its_own_issued_register_number` |
| เทสต์เช็คพอยต์ | **11 passed (37 assertions)** |
| Regression | **801 passed / 0 failed** · frontend **70 passed** |

---

## RISKS

| รหัส | ความเสี่ยง | ระดับ | สถานะ |
|---|---|---|---|
| RISK-23 **(ใหม่)** | ตรวจซ้ำเทียบข้อความตรงตัว — เลขต้นทางที่พิมพ์ต่างกันเล็กน้อย (เว้นวรรค/ขีด) จะไม่ถูกจับว่าซ้ำ | ต่ำ | ยอมรับได้ในขั้นนี้ · การเทียบแบบใกล้เคียง (fuzzy) เสี่ยงเตือนผิดจนคนเลิกอ่านคำเตือน ซึ่งแย่กว่า · ถ้าจำเป็นควรทำตอนมีข้อมูลจริงให้ปรับจูน |
| RISK-24 **(ใหม่)** | ยังไม่มีการส่งหนังสือที่รับแล้วเข้าสายงาน (routing) | ต่ำ | ตั้งใจ — เป็นงานของ INT-08 ตาม roadmap · หนังสือที่รับแล้วอยู่ในสถานะ `registered` รอการเดินเรื่อง |
| RISK-20 (จาก INT-05) | เลือกหน่วยงานเจ้าของเรื่องได้ทุกหน่วยงาน | กลาง | ยังเปิด — ผูกกับ Authority Matrix ของ INT-08 |
| RISK-22 (จาก INT-06) | เทสต์ concurrency ไม่อยู่ใน CI | กลาง | ยังเปิด — วางไว้ที่ INT-20 |

**ไม่มี blocker สำหรับ INT-08**

---

## NEXT

**→ INT-08 — Workflow + Authority**

เป็นเช็คพอยต์ที่ใหญ่ที่สุดที่เหลือ และเป็นจุดที่ปิดความเสี่ยงค้างสองข้อ

1. `SarabanAssigneeResolver implements SubjectAssigneeResolver` แล้วเพิ่มเข้า
   `AssigneeResolverRegistry::RESOLVERS` — **จุดที่ 2 ใน 4 จุดที่ต้องแก้ไฟล์ Core**
   ตามสัญญา INT-01 §2
2. นิยามสายงานของหนังสือใน `workflow_definitions` + `workflow_versions`
   (REUSE engine เดิมทั้งหมด ห้ามสร้าง engine ใหม่ — SoT §11)
3. **Authority Matrix เป็น projection** จาก `personnel_assignments` + `positions`
   + `org_unit_managers` + `workflow_delegations` — ห้ามสร้างทะเบียนอำนาจซ้ำ (SoT §13)
4. **ปิด RISK-20** — จำกัดหน่วยงานเจ้าของเรื่องตามอำนาจที่ resolve ได้จริง
5. ผูกการออกเลขหนังสือส่งเข้ากับขั้นตอนอนุมัติ (เลขออกเมื่อได้รับอนุมัติ ไม่ใช่ตอนสร้าง)
