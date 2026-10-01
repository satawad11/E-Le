# INT-12 — FINALIZATION + QR + VERIFICATION

**Source of Truth:** `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md`
**Checkpoint:** INT-12 (ROADMAP บรรทัดที่ 13)
**วันที่:** 2026-09-05
**สถานะ:** ✅ ผ่าน Definition of Done

---

## DONE

เช็คพอยต์นี้ทำให้หนังสือ "ออกจากระบบ" ได้จริง — จากฉบับที่ลงนามแล้ว กลายเป็น
**ฉบับสมบูรณ์ที่พิมพ์ออกไปแล้วยังตรวจสอบย้อนกลับได้** แม้กระดาษจะหลุดจากระบบไปแล้ว

| ข้อบังคับ SoT | สถานะ |
|---|---|
| §8 ออกฉบับสมบูรณ์ (`final`) หลังลงนามครบ | ✅ `FinalizationService` |
| §8 ออกได้ครั้งเดียว ไม่ซ้ำซ้อน | ✅ `finalized_at` + ตรวจก่อนออก |
| §12 QR บนหน้าเอกสารสำหรับตรวจสอบ | ✅ `SarabanPdfRenderer::drawQrCode()` |
| §12 หน้าตรวจสอบสาธารณะ ไม่ต้องเข้าสู่ระบบ | ✅ `/verify-saraban/{hash}` |
| §12 โทเคนเดาไม่ได้ + จำกัดอัตรา | ✅ `Str::random(40)` + `throttle:verify` |
| §12 บอกได้ว่าเนื้อหาถูกแก้ภายหลังหรือไม่ | ✅ `content_hash` + `wasAltered()` |
| §14 แสดงผู้ลงนามตาม snapshot ณ วันลงนาม | ✅ ปิด **RISK-31** |
| §10 เวลาทุกจุดผ่าน `TrustedClock` | ✅ รวมถึง `verified_at` ของหน้าตรวจสอบ |

### 1. การผนึกเอกสาร (seal) — แยก "ตัวชี้" ออกจาก "ลายนิ้วมือ"

`DocumentVerificationService` เก็บค่าสองตัวที่ทำหน้าที่ต่างกันโดยตั้งใจ:

- **`verification_hash`** — โทเคนสุ่ม 40 ตัวอักษร เป็น *ตัวชี้* ที่อยู่ใน QR
  ไม่ได้มาจากเนื้อหา จึงเดาไม่ได้แม้รู้เลขที่หนังสือ และไม่เปลี่ยนตลอดอายุเอกสาร
  (QR ที่พิมพ์ลงกระดาษไปแล้วต้องใช้ได้ตลอด)
- **`content_hash`** — SHA-512 ของสาระสำคัญ ณ วันออกหนังสือ เป็น *ลายนิ้วมือ*
  ใช้เทียบว่าทะเบียนถูกแก้ภายหลังหรือไม่

ถ้าใช้ค่าเดียวกันทำทั้งสองอย่าง การแก้ข้อมูลจะทำให้ QR บนกระดาษใช้ไม่ได้ทันที —
ซึ่งกลับกลายเป็นการ **ลบหลักฐาน** แทนที่จะ **เปิดโปง** การแก้ไข

### 2. QR วาดเป็นสี่เหลี่ยม ไม่ใช่ภาพ

`drawQrCode()` อ่าน matrix จาก `chillerlan/php-qrcode` (REUSE — มีอยู่แล้วในระบบ
สำหรับใบลา) แล้ววาดแต่ละ module ด้วย `$pdf->Rect(..., 'F')` — รักษาหลักการเดิม
ของ `SarabanPdfRenderer` ตั้งแต่ INT-10 ว่า **ไม่มีโค้ดจัดการรูปภาพในสายการเรนเดอร์เลย**
ไม่ต้องเขียนไฟล์ชั่วคราว และไม่มีทางรับ input เป็นภาพจากผู้ใช้

### 3. หน้าตรวจสอบสาธารณะ — คืนเฉพาะสิ่งที่อยู่บนกระดาษอยู่แล้ว

ผู้เปิดหน้านี้คือคนทั่วไปที่ถือเอกสารอยู่ในมือ ไม่ใช่ผู้ใช้ที่ผ่านการยืนยันตัวตน
API จึงคืน **เลขที่ เรื่อง ประเภท หน่วยงาน วันที่ และผู้ลงนาม** เท่านั้น
— ไม่คืนสาระสำคัญ ไฟล์แนบ เส้นทางการเสนอ หรือความเห็นที่เกษียนไว้

---

## CHANGED

| ประเภท | รายการ |
|---|---|
| CREATE | `DocumentVerificationService`, `FinalizationService`, `DocumentVerificationController`, `VerifyDocumentView.vue` |
| EXTEND | `SarabanPdfRenderer::drawQrCode()`, `SignatureController::finalize()`, `DocumentPolicy::finalize()` |
| EXTEND | `saraban_documents` +3 คอลัมน์ (migration) |
| REUSE | `chillerlan/php-qrcode`, `TrustedClock`, `StoredFile`, `SecureFileAccessService`, `throttle:verify`, route meta `bare`+`public`, `LoadingState`/`ErrorState` |
| MODIFY Core | **ไม่มี** |

---

## FILES

**Backend**
- `database/migrations/2026_09_05_100000_add_verification_to_saraban_documents.php`
- `app/Services/Saraban/DocumentVerificationService.php`
- `app/Services/Saraban/FinalizationService.php`
- `app/Services/Saraban/SarabanPdfRenderer.php` *(แก้ไข — เพิ่ม `drawQrCode()`)*
- `app/Http/Controllers/Saraban/DocumentVerificationController.php`
- `app/Http/Controllers/Saraban/SignatureController.php` *(แก้ไข — `finalize()`)*
- `app/Policies/Saraban/DocumentPolicy.php` *(แก้ไข — `finalize()`)*
- `routes/api.php` *(แก้ไข)*
- `tests/Feature/SarabanFinalizationAndVerificationTest.php`
- `tests/Feature/RouteAuthorizationAuditTest.php` *(แก้ไข — allowlist)*

**Frontend**
- `src/views/saraban/VerifyDocumentView.vue`
- `src/router/index.js` *(แก้ไข — `/verify-saraban/:hash`)*

---

## MIGRATIONS

`2026_09_05_100000_add_verification_to_saraban_documents.php`

| คอลัมน์ | ชนิด | หมายเหตุ |
|---|---|---|
| `verification_hash` | `string(64)` nullable **unique** | โทเคนใน QR |
| `content_hash` | `string(128)` nullable | SHA-512 ณ วันออกหนังสือ |
| `finalized_at` | `timestamp` nullable | ออกฉบับสมบูรณ์แล้วเมื่อใด |

**Migration safe:** เพิ่มคอลัมน์ทั้งหมดเป็น nullable ไม่มี default ที่ต้องเขียนทับ
ไม่แตะคอลัมน์เดิม ไม่มี backfill — เอกสารที่ยังไม่ออกฉบับสมบูรณ์มีค่าเป็น `NULL`
ซึ่งเป็นความหมายที่ถูกต้องอยู่แล้ว · `down()` ถอดคอลัมน์ (ถอด unique index ก่อน)

ตรวจ `migrate --force` จากฐานว่างบน MySQL 8 ผ่าน

---

## TESTS

`tests/Feature/SarabanFinalizationAndVerificationTest.php` — **13 tests / 70 assertions ผ่านทั้งหมด**

1. ออกฉบับสมบูรณ์ได้เมื่อลงนามแล้ว และได้ `verification_hash`
2. ออกซ้ำไม่ได้ (422)
3. ออกไม่ได้ถ้ายังไม่ลงนาม
4. ฉบับที่ออกมี stage `final` และมี annotation ชนิด QR
5. `verification_hash` ไม่ซ้ำกันข้ามเอกสาร
6. `content_hash` เปลี่ยนตามสาระสำคัญ — `wasAltered()` จับการแก้ทะเบียนได้
7. หน้าตรวจสอบสาธารณะเปิดได้โดยไม่ต้องเข้าสู่ระบบ
8. โทเคนผิดได้ 404
9. เอกสารที่ถูกยกเลิกคืน `is_valid=false`, `cancelled=true`
10. เอกสารที่ยังไม่ออกฉบับสมบูรณ์คืน `is_valid=false`
11. แสดงผู้ลงนามตาม snapshot ไม่ใช่ข้อมูลปัจจุบัน (เปลี่ยนตำแหน่งผู้ใช้แล้วผลลัพธ์ไม่เปลี่ยน)
12. API สาธารณะไม่คืนสาระสำคัญ/ไฟล์แนบ
13. endpoint QR คืน `image/svg+xml`

---

## SECURITY

| ประเด็น | การจัดการ |
|---|---|
| เดาโทเคน | `Str::random(40)` (CSPRNG) + `throttle:verify` (limiter เดิมของใบลา) |
| ข้อมูลรั่วผ่านหน้าสาธารณะ | คืนเฉพาะฟิลด์ที่ปรากฏบนกระดาษ — มี test ยืนยันว่าไม่มีสาระสำคัญ/ไฟล์แนบ |
| แก้ทะเบียนหลังออกหนังสือ | `content_hash` เปิดโปงได้ — หน้าตรวจสอบขึ้นเตือนชัดเจน |
| ออกหนังสือโดยผู้ไม่มีอำนาจ | `DocumentPolicy::finalize()` + `ability:saraban.signature.sign` + **`step_up`** |
| เวลาที่เชื่อถือได้ | `verified_at` ผ่าน `TrustedClock` (SoT §10) — **guard ของ INT-01 จับได้ตอน regression และแก้ที่ต้นเหตุ ไม่ผ่อนกฎ** |
| route สาธารณะหลุดโดยไม่ตั้งใจ | `RouteAuthorizationAuditTest` บังคับ allowlist — เพิ่ม 2 URI พร้อมเหตุผลกำกับในโค้ด |

---

## REGRESSION

```
php artisan test          →  861 passed, 8 skipped (3156 assertions), 0 failed
vendor/bin/pint --test    →  559 files, 0 style issues
```

**ระหว่างทางมี 2 ข้อผิดพลาดที่ guard ของโครงการจับได้เอง และแก้ที่ต้นเหตุทั้งคู่:**

1. `RouteAuthorizationAuditTest` — route ตรวจสอบสาธารณะไม่มี `auth:sanctum`
   → เพิ่มเข้า allowlist **พร้อมคอมเมนต์อธิบายเหตุผล** (เจตนา ไม่ใช่ความพลาด)
   ตามแบบเดียวกับ `api/leaves/verify/{hash}` ที่มีอยู่ก่อน
2. `SarabanCoreContractTest` — `DocumentVerificationController` เรียก `now()` ตรง ๆ
   → inject `TrustedClock` แทนการยกเว้นไฟล์นี้ออกจากกฎ

**Frontend gates:** ESLint ✅ · `vue-tsc` ✅ · Vitest 70 tests ✅ · production build ✅

---

## UI-QA

- REUSE `LoadingState` (`skeleton-card`), `ErrorState`, `getErrorMessage` เดิมทั้งหมด
- ใช้ route meta `bare` + `public` แบบเดียวกับหน้าตรวจสอบใบลา — แสดงนอก app shell
- สถานะรวมอ่านได้ทันทีด้วยสีเขียว/แดง + `role="status"` และ **ข้อความที่ไม่พึ่งสีอย่างเดียว**
- วันที่แสดงเป็น พ.ศ. ตามรูปแบบเดิมของระบบ
- ตรวจผ่าน component tests และการอ่านโค้ด

**ข้อจำกัดที่ต้องระบุ:** ไม่ได้ทำ visual QA ผ่านเบราว์เซอร์จริง เพราะการเข้าสู่ระบบ
ต้องกรอกรหัสผ่านซึ่งอยู่นอกขอบเขตที่ผมทำได้ · **ผู้ดูแลระบบควรตรวจด้วยตาที่**
`http://localhost:8001/verify-saraban/{hash}` **ทั้งบนจอคอมพิวเตอร์และมือถือ**
(หน้านี้ไม่ต้องเข้าสู่ระบบ จึงตรวจได้ทันทีหลังออกหนังสือหนึ่งฉบับ)

---

## EVIDENCE

- ผลรัน `php artisan test` และ `pint --test` ตามหัวข้อ REGRESSION
- `SarabanCoreContractTest` ยังผ่าน = **ไม่มีการสร้างตาราง Core ซ้ำ ไม่มี Auth/Hash/Mail
  ตรง ๆ ไม่มี `department_id` ไม่มีการอ่านนาฬิกาเครื่อง** ในโค้ด SARABAN ทั้งหมด
- `migrate --force` จากฐานว่างผ่าน
- ข้อ 6 และ 11 ของชุดทดสอบเป็นหลักฐานว่ากลไกตรวจการแก้ไขและ snapshot ผู้ลงนามทำงานจริง
  ไม่ใช่แค่มีคอลัมน์

---

## RISKS

| รหัส | ความเสี่ยง | ระดับ | สถานะ |
|---|---|---|---|
| ~~RISK-31~~ | ไม่มีหน้าแสดงหลักฐานการลงนาม | — | ✅ **ปิด** — อยู่บนหน้าตรวจสอบสาธารณะแล้ว |
| RISK-32 **(ใหม่)** | ตำแหน่ง QR บนหน้าเอกสารส่งมาจาก client (มีค่า default) ยังไม่มีตำแหน่งมาตรฐานต่อประเภทหนังสือ | ต่ำ | ค่าที่รับถูก validate ช่วงแล้ว · จะผูกกับ template ของประเภทหนังสือที่ **INT-16** |
| RISK-33 **(ใหม่)** | `content_hash` ครอบคลุมสาระสำคัญในทะเบียน แต่ไม่ครอบคลุมไฟล์แนบ | ต่ำ | ความสมบูรณ์ของไฟล์คุมด้วย `file_hash` ของ `document_versions` + `evidenceIsIntact()` อยู่แล้ว — เป็นคนละชั้นโดยตั้งใจ |
| RISK-21 | ต้องตั้งชุดเลขหนังสือส่งออกก่อนลงนาม | กลาง | ยกมาจาก INT-09 — จะมี UI ตั้งค่าที่ INT-16 |
| RISK-22 | concurrency test ยังไม่อยู่ใน CI | กลาง | ยกมาจาก INT-06 — วางแผนไว้ที่ INT-20 |
| RISK-29 | step-up ใช้รหัสผ่านอย่างเดียว ยังไม่มี MFA | กลาง | ยกมาจาก INT-11 |
| RISK-30 | ตำแหน่งลายเซ็นยังกรอกเป็นตัวเลข | ต่ำ | ยกมาจาก INT-11 |
| SEC-GAP-04 | ยังไม่มี idempotency key | กลาง | วางแผนไว้ที่ INT-15 |

**ไม่มี blocker สำหรับ INT-13**

---

## NEXT

**→ INT-13 — Distribution + Inbox + Acknowledgement**

1. ส่งหนังสือฉบับสมบูรณ์ไปยังหน่วยงาน/บุคคลปลายทาง
2. กล่องหนังสือเข้า — **REUSE `OrgScopeResolver`** สำหรับขอบเขตการมองเห็น
3. การรับทราบ/ลงรับ พร้อมบันทึกเวลาผ่าน `TrustedClock` และ audit hash chain
4. **REUSE `NotificationCenter`** ที่มีอยู่แล้ว — ห้ามสร้างชั้นแจ้งเตือนใหม่
