# INT-11 — E-KASIAN + SIGNATURE + STEP-UP RE-AUTH

**Source of Truth:** `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md`
**Checkpoint:** INT-11 (ROADMAP บรรทัดที่ 12)
**วันที่:** 2026-09-05
**สถานะ:** ✅ ผ่าน Definition of Done

---

## DONE

เช็คพอยต์ที่อ่อนไหวที่สุดของโครงการ — **ปิด SEC-GAP-01 ซึ่งเป็นช่องว่างสุดท้าย
ที่ค้างมาตั้งแต่ INT-00** และเป็นการแก้ที่ **Core Identity** ตามที่ SoT §4 กำหนด

| ข้อบังคับ SoT | สถานะ |
|---|---|
| §24 การกระทำอ่อนไหวต้องยืนยันตัวตนซ้ำ (server-side) | ✅ **SEC-GAP-01 ปิดแล้ว** |
| §24 ปิดด่านนี้บน production ไม่ได้ | ✅ `ProductionEnvironmentGuard` |
| §14 หลักฐานการลงนามครบ 11 รายการ | ✅ `saraban_signature_evidences` |
| §14 ลายเซ็นไม่ใช่ภาพจากเบราว์เซอร์ | ✅ เก็บเป็นพิกัดเส้น เรนเดอร์เป็นเวกเตอร์ที่ server |
| §15 **ลายมือ ≠ ลายเซ็น** | ✅ คนละสิทธิ์ คนละ policy ไม่สร้างหลักฐานการลงนาม |
| §16 งานสารบรรณลงนามแทนไม่ได้ | ✅ ต้องมีอำนาจลงนามกับหน่วยงานนั้นด้วยตนเอง |
| §15 stylus / touch / mouse | ✅ `HandwritingCanvas` (REUSE `signature_pad` ที่มีอยู่แล้ว) |

### SEC-GAP-01 — การยืนยันตัวตนซ้ำ (Core Identity)

**ปัญหา:** โทเคนของระบบมีอายุ 8 ชั่วโมง (`SANCTUM_EXPIRATION=480`) การมีโทเคน
ที่ยังไม่หมดอายุจึงไม่ได้แปลว่า "คนที่กดตอนนี้คือเจ้าของบัญชี" — เครื่องที่เปิด
ค้างไว้ลงนามหนังสือราชการแทนเจ้าของได้ ซึ่งเป็นความเสียหายที่ย้อนกลับไม่ได้

**กลไก (เป็นของระบบกลาง ทุกโมดูลใช้ได้):**

| ส่วน | หน้าที่ |
|---|---|
| `personal_access_tokens.last_authenticated_at` | เวลายืนยันตัวตนแข็งครั้งล่าสุด — **ผูกกับโทเคนใบนั้น ไม่ใช่กับตัวผู้ใช้** |
| `StepUpAuthentication` | ตัดสินว่า "เพิ่งยืนยัน" หรือยัง + ยืนยันด้วยรหัสผ่าน + คืนหลักฐานอ้างอิง |
| middleware `step_up` | ด่านที่ route ของการกระทำอ่อนไหว · คืน **423 Locked** + `code: reauthentication_required` |
| `POST /api/auth/confirm-password` | ยืนยันตัวตน — ไม่ออกโทเคนใหม่ ไม่เปลี่ยนสิทธิ์ · จำกัดอัตราด้วย `throttle:password-change` |

**การตัดสินใจสำคัญ 3 ข้อ:**

| # | เรื่อง | ตัดสิน | เหตุผล |
|---|---|---|---|
| 1 | ผูกกับ **โทเคน** ไม่ใช่ผู้ใช้ | ✅ | ยืนยันบนเครื่องหนึ่งต้องไม่ทำให้อีกเครื่องที่ล็อกอินค้างไว้ลงนามได้ตามไปด้วย |
| 2 | ต่ออายุโทเคน **ยกเวลาเดิมมา ไม่ตั้งใหม่** | ✅ | ถ้าตั้งใหม่ทุกครั้งที่ต่ออายุ ผู้ใช้จะยืดช่วงยืนยันได้ไม่รู้จบเพียงกดต่ออายุ — ด่านจะไม่มีความหมาย (มีเทสต์ยืนยัน) |
| 3 | **423 Locked** ไม่ใช่ 401/403 | ✅ | สื่อว่า "ทำได้ แต่ต้องปลดล็อกก่อน" หน้าเว็บจึงเปิดกล่องกรอกรหัสผ่านได้เอง แทนที่จะแสดงเป็นข้อผิดพลาด |

**ขอบเขตผลกระทบ:** ด่านนี้ผูกกับ **route ของสารบรรณเท่านั้น** ในเช็คพอยต์นี้
โมดูลเดิม (ลา/ลงเวลา/ล่วงเวลา) ไม่ได้รับผลใด ๆ — ยืนยันด้วย regression 848 เทสต์

### ลายมือ ≠ ลายเซ็น (SoT §15)

| | เกษียน (`endorse`) | ลงนาม (`sign`) |
|---|---|---|
| สิทธิ์ | `saraban.endorse.create` | `saraban.signature.sign` |
| ยืนยันตัวตนซ้ำ | ❌ ไม่ต้อง | ✅ **ต้อง** |
| หลักฐานการลงนาม | ❌ ไม่สร้าง | ✅ สร้าง |
| ขั้นเวอร์ชันที่ได้ | `endorsed` | `signed` |

ถ้าปฏิบัติกับสองอย่างนี้เหมือนกัน จะได้หลักฐานการลงนามปลอมเต็มไปหมดจากคน
ที่เพียงเขียนความเห็นประกอบ

### ลายเซ็นเป็นเส้น ไม่ใช่ภาพ

`signature_pad` อ่านค่าออกด้วย `toData()` (พิกัดดิบ) **ไม่ใช่ `toDataURL()`** —
ฝั่ง server วาดเป็นเส้นเวกเตอร์ด้วย FPDI จึงคมทุกระดับการขยาย และยังคงข้อบังคับ
ของ SoT §14 ที่ห้ามใช้ภาพจากเบราว์เซอร์เป็นผลลัพธ์

หน้าเว็บย่อพิกัดจากผืนผ้าใบให้พอดีกรอบปลายทางก่อนส่ง มิฉะนั้นลายเซ็นที่เขียน
บนจอใหญ่จะล้นกรอบตอนเรนเดอร์

---

## CHANGED

### ปัญหาที่พบระหว่างทาง 3 ข้อ (ทั้งหมดเจอจากเทสต์)

**1. `actingAs()` ของ Sanctum ใช้ `TransientToken` ที่ไม่มีแถวในฐานข้อมูล**
เทสต์ 9 ข้อแรกได้ 423 ทั้งหมด — ซึ่งเป็นพฤติกรรมที่ **ถูกต้อง** (fail closed)
แต่แปลว่าเทสต์กำลังเดินคนละเส้นทางกับผู้ใช้จริง จึงเปลี่ยนไปใช้ **โทเคนจริง**
เหมือนที่หน้าเว็บใช้

**2. guard จำผู้ใช้คนแรกข้ามคำขอในเทสต์เดียวกัน**
พบว่าคำขอที่สองยังทำงานในนามผู้ใช้คนแรก (พิสูจน์ด้วยการเรียก `/api/user` สองครั้ง
แล้วได้ id เดิม) — ในคำขอจริงแต่ละครั้งเป็นคนละ process จึงไม่มีปัญหา แต่ในเทสต์
ต้องเรียก `forgetGuards()` ก่อนเปลี่ยนตัวตน มิฉะนั้นเทสต์จะผ่าน/ไม่ผ่านด้วยเหตุผลผิด

**3. คอลัมน์ใหม่คืนค่าเป็นสตริง ไม่ใช่ Carbon**
`PersonalAccessToken` ของ Sanctum ไม่รู้จัก `last_authenticated_at` จึงไม่ cast
→ `->gt()` พังตอน runtime · **แก้ที่โมเดล ไม่ใช่ที่ผู้เรียกแต่ละคน** โดยประกาศ
`App\Models\AccessToken` แล้วลงทะเบียนผ่าน `Sanctum::usePersonalAccessTokenModel()`
— ถ้าแก้ทีละจุดที่เรียก จะเหลือกับดักให้คนถัดไปลืมและพังตอน runtime

### ไฟล์ Core ที่แก้ (3 จุด — ทั้งหมดเป็นการเพิ่ม)

| ไฟล์ | การแก้ |
|---|---|
| `bootstrap/app.php` | เพิ่ม alias `step_up` |
| `config/auth.php` | เพิ่มหมวด `step_up` (เปิด/ปิดได้ + ช่วงเวลา) |
| `AppServiceProvider` | ลงทะเบียนโมเดลโทเคนที่ cast คอลัมน์ใหม่ |
| `AuthController` | บันทึกเวลายืนยันตอนล็อกอิน · ยกเวลาเดิมตอนต่ออายุ · endpoint ยืนยันรหัสผ่าน |
| `ProductionEnvironmentGuard` | ห้ามปิด step-up บน production |

---

## FILES

**เพิ่มใหม่ (11):**

```
backend/database/migrations/2026_09_04_170000_add_step_up_assurance_to_access_tokens.php
backend/database/migrations/2026_09_04_180000_create_saraban_signature_evidences.php
backend/app/Models/AccessToken.php
backend/app/Models/Saraban/SignatureEvidence.php
backend/app/Services/StepUpAuthentication.php
backend/app/Http/Middleware/RequireRecentAuthentication.php
backend/app/Services/Saraban/SignatureService.php
backend/app/Services/Saraban/SignatureException.php
backend/app/Http/Controllers/Saraban/SignatureController.php
backend/tests/Feature/SarabanSignatureAndStepUpTest.php
frontend/src/components/ui/HandwritingCanvas.vue
frontend/src/components/saraban/SignDocumentModal.vue
```

---

## MIGRATIONS

| Migration | ประเด็นความปลอดภัย |
|---|---|
| `..._add_step_up_assurance_to_access_tokens` | **เพิ่มคอลัมน์เดียวแบบ nullable** · backfill ด้วย `created_at` ของโทเคนเดิม เพื่อไม่ให้ผู้ที่เพิ่งล็อกอินถูกบังคับยืนยันซ้ำทันทีเพียงเพราะระบบอัปเกรด |
| `..._create_saraban_signature_evidences` | ตารางใหม่ล้วน · `restrictOnDelete` ทุก FK เพราะเป็นหลักฐาน |

ทั้งคู่ additive · `down()` ถอนได้สะอาด

---

## TESTS

`SarabanSignatureAndStepUpTest` — **14 กรณี / 53 assertions**

| กลุ่ม | ครอบคลุม |
|---|---|
| **Step-up** | **โทเคนที่เปิดค้างไว้ลงนามไม่ได้ (423 + code)** · ยืนยันรหัสผ่านแล้วลงนามได้ · รหัสผ่านผิดไม่เปิดช่วง · **ต่ออายุโทเคนไม่ยืดช่วงยืนยัน** · production ปิดด่านไม่ได้ |
| หลักฐาน | ครบทั้ง 11 รายการรวม `auth_token_id` และ `reauthenticated_at` · **แก้/ลบไม่ได้** · **แก้ไฟล์ที่ลงนามแล้วตรวจพบได้** |
| ลายเซ็น | **เก็บเป็นพิกัดเส้น ไม่มีคีย์ `image`** |
| ลายมือ ≠ ลายเซ็น | **เกษียนไม่สร้างหลักฐานการลงนาม และไม่ต้องยืนยันตัวตน** · เกษียนใช้สิทธิ์คนละตัวกับลงนาม |
| สิทธิ์ | เจ้าหน้าที่สารบรรณลงนามแทนไม่ได้ · ลงนามซ้ำไม่ได้ · เข้า audit กลาง |

---

## SECURITY

| ประเด็น | ก่อน INT-11 | หลัง INT-11 |
|---|---|---|
| เครื่องที่เปิดค้างไว้ลงนามแทนเจ้าของ | ทำได้ | **ทำไม่ได้** — ต้องยืนยันตัวตนภายใน 10 นาที |
| ยืดช่วงยืนยันด้วยการต่ออายุโทเคน | — | ทำไม่ได้ (ยกเวลาเดิมมา) |
| ปิดด่านบน production | — | container ไม่ขึ้น |
| หลักฐานว่าลงนามจาก session ใด | ไม่มี | `auth_token_id` + `reauthenticated_at` + IP + UA |
| แก้ไฟล์ที่ลงนามแล้ว | ตรวจไม่ได้ | ตรวจพบผ่าน `document_hash` |
| แก้หลักฐานการลงนาม | — | โมเดลปฏิเสธ update/delete |
| งานสารบรรณลงนามแทน | — | policy ปฏิเสธ (ต้องมีอำนาจลงนามเอง) |
| ลายมือถูกนับเป็นลายเซ็น | — | คนละสิทธิ์ คนละ policy ไม่สร้างหลักฐาน |

**SEC-GAP ทั้ง 4 ข้อจาก INT-00:** SEC-GAP-01 ✅ (นี้) · SEC-GAP-02 ✅ (INT-04) ·
SEC-GAP-03 ✅ (INT-04) · SEC-GAP-04 ⏳ idempotency → INT-15

---

## REGRESSION

| รอบ | ผล |
|---|---|
| Baseline INT-10 | 834 passed · 0 failed |
| **หลัง INT-11** | ✅ **848 passed · 8 skipped · 0 failed** · 3086 assertions |
| Frontend | ✅ 70 passed · ESLint ✅ · vue-tsc ✅ · build ✅ |
| Pint | ✅ 554 files |

**จุดเสี่ยงที่สุดของทั้งโครงการจนถึงตอนนี้** — INT-11 แตะ `AuthController`,
`AppServiceProvider` และโมเดลโทเคนของ Sanctum ซึ่งอยู่บนเส้นทางเข้าสู่ระบบของ
**ทุกผู้ใช้ทุกโมดูล** · `AuthTest`, `AuthenticationHardeningTest`,
`ProfilePasswordChangeTest`, `SystemRoleProtectionTest` เขียวทั้งหมด

---

## UI-QA

| ข้อบังคับ SoT §2 | ผล |
|---|---|
| ใช้ UI kit เดิม | ✅ `AppModal`, `AppButton`, `AppInput` · `HandwritingCanvas` ไปอยู่ใน `components/ui/` เพราะเป็น **ของกลาง** ที่โมดูลอื่นใช้ได้ (ตรงกับ UI matrix §7.4) |
| ไม่เพิ่ม dependency | ✅ **REUSE `signature_pad` ที่มีอยู่แล้ว** ตามที่ UI matrix §8 ระบุ |
| Theme / tokens | ✅ ไม่เพิ่มสีใหม่ |
| a11y | ✅ canvas มี `role="img"` + `aria-label` ภาษาไทย · ช่องรหัสผ่านมี `autocomplete="current-password"` |
| ภาษาไทย | ✅ ทั้งหมด |

**UX ของการยืนยันตัวตนซ้ำ:** ไม่ถามรหัสผ่านล่วงหน้าทุกครั้ง — กดลงนามก่อน
ถ้า server ตอบ 423 จึงเปิดช่องกรอกรหัสผ่านให้ พร้อมข้อความอธิบาย ไม่ใช่แสดงเป็น error
(ผู้ใช้ที่เพิ่งล็อกอินจะไม่ถูกถามซ้ำโดยไม่จำเป็น)

> ยังไม่ได้เปิดเบราว์เซอร์ตรวจด้วยตาด้วยเหตุผลเดิม (ต้องกรอกรหัสผ่านเข้าระบบ)

---

## EVIDENCE

| หลักฐาน | ที่มา / ผล |
|---|---|
| **โทเคนค้างลงนามไม่ได้** | `test_signing_is_refused_when_the_user_has_not_recently_re_authenticated` |
| ยืนยันแล้วลงนามได้ | `test_confirming_the_password_opens_the_window_and_signing_then_succeeds` |
| **ต่ออายุไม่ยืดช่วง** | `test_refreshing_the_token_carries_the_original_confirmation_time_not_a_new_one` |
| หลักฐานครบ | `test_the_signature_evidence_records_everything_the_master_requires` |
| แก้ไฟล์แล้วจับได้ | `test_tampering_with_the_signed_file_is_detected` |
| ลายมือ ≠ ลายเซ็น | `test_endorsing_with_handwriting_does_not_create_signature_evidence` |
| ลายเซ็นไม่ใช่ภาพ | `test_a_signature_is_stored_as_stroke_coordinates_not_as_an_image` |
| เทสต์เช็คพอยต์ | **14 passed (53 assertions)** |
| Regression | **848 passed / 0 failed** · frontend **70 passed** |

---

## RISKS

| รหัส | ความเสี่ยง | ระดับ | สถานะ |
|---|---|---|---|
| ~~SEC-GAP-01~~ | ไม่มี step-up re-auth | — | ✅ **ปิด** |
| ~~RISK-28~~ | ลายมือ/ลายเซ็นวางไม่ได้ | — | ✅ **ปิด** |
| RISK-29 **(ใหม่)** | step-up ยืนยันด้วย **รหัสผ่าน** เท่านั้น ยังไม่มี MFA | กลาง | SoT §24 ระบุ "re-auth/MFA/assurance" — รหัสผ่านเป็นระดับที่ระบบนี้รองรับได้ตอนนี้ (ไม่มี MFA ทั้งระบบตั้งแต่ INT-00) · `StepUpAuthentication` ออกแบบให้เพิ่มวิธียืนยันได้โดยไม่แตะผู้เรียก |
| RISK-30 **(ใหม่)** | ตำแหน่งลายเซ็นกรอกเป็นตัวเลขในกล่องลงนาม ยังลากวางบนหน้ากระดาษไม่ได้ | ต่ำ | `PdfWorkspace` ของ INT-10 ลากวางได้อยู่แล้ว — เชื่อมสองส่วนเข้าด้วยกันเป็นงาน UI ล้วน ทำได้ภายหลังโดยไม่กระทบสัญญาข้อมูล |
| RISK-31 **(ใหม่)** | ยังไม่มีหน้าแสดงหลักฐานการลงนามให้ผู้ใช้ดู (มีแต่ endpoint) | ต่ำ | จะรวมกับหน้าตรวจสอบสาธารณะที่ **INT-12** ซึ่งเป็นที่ที่ QR + การตรวจสอบอยู่ |

**ไม่มี blocker สำหรับ INT-12**

---

## NEXT

**→ INT-12 — Finalization + QR + Verification**

1. ฉบับสมบูรณ์ (`final`) พร้อม QR บนหน้าเอกสาร — **REUSE `chillerlan/php-qrcode` ที่มีอยู่แล้ว**
2. หน้าตรวจสอบสาธารณะ — **REUSE รูปแบบของใบลา** (`verification_hash` + `throttle:verify`
   + route meta `bare`+`public`) ซึ่งพิสูจน์แล้วว่าใช้งานได้จริง
3. แสดงหลักฐานการลงนามบนหน้าตรวจสอบ (ปิด RISK-31)
