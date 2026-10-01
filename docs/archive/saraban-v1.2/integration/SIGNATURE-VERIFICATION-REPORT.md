# SIGNATURE-VERIFICATION-HARDENING — รายงานผล

**Source of Truth:** `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md` §8, §12, §14, §18, §24, §26
**วันที่:** 2026-09-05
**สถานะ:** ✅ ผ่าน Definition of Done ทุกข้อ
**เอกสารประกอบ:** `SIGNATURE-VERIFICATION-GAP.md` (ผลสำรวจก่อนลงมือ) · `SIGNATURE-VERIFICATION.md` (คู่มือความสามารถ)

---

## DONE

| # | ที่สั่ง | ผล |
|---|---|---|
| 1 | Signature Verification Service | ✅ `SignatureVerificationService` — 14 สถานะ · 9 ข้อตรวจ · คำนวณสดทุกครั้ง |
| 2 | Document Integrity Verification | ✅ เทียบ SHA-256 ของไฟล์จริง · แยก "ไฟล์หาย" ออกจาก "ไฟล์ถูกแก้" |
| 3 | Hash-before-sign / signed-version binding | ✅ ตรวจการผูกสามทาง หลักฐาน ↔ เวอร์ชัน ↔ หนังสือ |
| 4 | Signer Identity Verification | ✅ ผู้สร้างฉบับที่ลงนามต้องเป็นผู้ลงนามคนเดียวกัน |
| 5 | Authority Verification | ✅ ใช้ `authority_snapshot` เป็นหลักฐาน + ตรวจสถานะปัจจุบันเป็นคำเตือน |
| 6 | Signing Order Verification | ✅ บันทึก `signing_sequence` ตอนลงนาม · ตรวจกับลำดับเวลาจริง |
| 7 | Certificate Validation-ready | ✅ 7 คอลัมน์ + ตรรกะการตรวจ · **ไม่แสดงผลเมื่อไม่มีใบรับรอง** |
| 8 | Certificate Expiry/Revocation-ready | ✅ อายุ · การเพิกถอนก่อน/หลังลงนาม · ผู้ออกที่เชื่อถือ |
| 9 | Trusted Timestamp Validation-ready | ✅ 3 คอลัมน์ + ตรวจความสอดคล้องของเวลาและ TSA ที่เชื่อถือ |
| 10 | Signature Evidence Package | ✅ `GET .../verification/report` |
| 11 | Re-verification | ✅ ตรวจซ้ำได้ทุกเมื่อ · **ไม่เก็บผลลง DB เลย** |
| 12 | Revocation Request Workflow | ✅ `saraban_signature_revocations` + บริการ + endpoint |
| 13 | Approve Revocation | ✅ สิทธิ์แยก + `step_up` + ผู้ขอ ≠ ผู้ตัดสิน |
| 14 | Reject Revocation | ✅ ต้องมีเหตุผล · คำขอยังอยู่ในทะเบียน |
| 15 | Verification Report | ✅ ผลตรวจ + ชุดหลักฐาน + ประวัติคำขอ |
| 16 | Internal Verification UI | ✅ ขยาย `DocumentTools.vue` และ `OperationsTools.vue` เดิม **ไม่สร้างหน้าใหม่** |
| 17 | Public Verification policy | ✅ สถานะย่อเท่านั้น · ตรึงด้วยเทสต์ว่าไม่รั่วรายละเอียดภายใน |
| 18 | Verification API | ✅ 5 endpoint + คู่ `api/v1/...` อัตโนมัติ |

---

## CHANGED — ข้อบกพร่องที่พบระหว่างสำรวจ และปิดแล้ว

การสำรวจก่อนลงมือพบสามเรื่องที่ไม่ได้อยู่ในรายการที่สั่ง

### RISK-64 — `is_valid` ของหน้าตรวจสอบสาธารณะไม่ได้ดูลายเซ็นเลย

เดิมคิดจากสามอย่าง: ออกฉบับสมบูรณ์แล้ว · ไม่ถูกยกเลิก · เมทาดาทาไม่ถูกแก้ —
**ไม่แตะไฟล์และไม่แตะลายเซ็น**

> ไฟล์ PDF ที่ลงนามแล้วถูกสับเปลี่ยนบนดิสก์ หน้าตรวจสอบสาธารณะยังตอบว่า
> "ใช้อ้างอิงได้" ซึ่งเป็นคำตอบที่ผิดในกรณีที่สำคัญที่สุดของหน้านี้

**ปิดแล้ว** — `is_valid` มาจากบริการตรวจสอบตัวเดียวกับที่ผู้ใช้ภายในเห็น

### RISK-65 — `content_hash` ผนึกเฉพาะเมทาดาทา ไม่ผนึกไฟล์

ยังคงเป็นอย่างนั้น **โดยตั้งใจ** — แต่ตอนนี้มีการตรวจแฮชไฟล์เป็นข้อแยกต่างหาก
(`integrity`) ซึ่งจับสิ่งที่ `content_hash` จับไม่ได้ · สองข้อนี้ตอบคนละคำถาม:
`metadata_seal` จับ "เลขหนังสือถูกแก้ในฐานข้อมูล" · `integrity` จับ "ไฟล์ถูกสับเปลี่ยน"

### RISK-66 — ไม่มีอะไรตั้ง `STATUS_CANCELLED`

สถานะมีนิยามและถูกใช้ตัดสินสิทธิ์ในสามที่ของ `DocumentPolicy` **แต่ไม่มีเส้นทางใด
ในระบบที่ทำให้หนังสือเข้าสถานะนี้ได้** — โค้ดที่ตรวจสถานะซึ่งเกิดขึ้นไม่ได้ คือโค้ด
ที่ไม่เคยทำงาน · **ปิดแล้ว** — การอนุมัติเพิกถอนเป็นเส้นทางแรกที่ตั้งสถานะนี้

---

## FILES

| ไฟล์ | การเปลี่ยนแปลง |
|---|---|
| `backend/app/Services/Saraban/SignatureVerificationService.php` | **ใหม่** — หัวใจของเช็คพอยต์ |
| `backend/app/Services/Saraban/SignatureRevocationService.php` | **ใหม่** — วงจรเพิกถอน |
| `backend/app/Models/Saraban/SignatureRevocation.php` | **ใหม่** — คำขอที่ตัดสินแล้วแก้ไม่ได้ |
| `backend/app/Http/Controllers/Saraban/SignatureVerificationController.php` | **ใหม่** — 5 endpoint |
| `backend/database/migrations/2026_09_10_100000_create_saraban_signature_verification.php` | **ใหม่** — คอลัมน์ใบรับรอง/TSA + ตารางเพิกถอน |
| `backend/database/migrations/2026_09_10_110000_grant_saraban_signature_revocation.php` | **ใหม่** — ค่าตั้งต้นสิทธิ์ |
| `backend/app/Models/Saraban/SignatureEvidence.php` | ชนิดลายเซ็น ลำดับ ใบรับรอง TSA |
| `backend/app/Services/Saraban/SignatureService.php` | บันทึก `signature_type` และ `signing_sequence` ตอนลงนาม |
| `backend/app/Http/Controllers/Saraban/DocumentVerificationController.php` | หน้าสาธารณะใช้ผลตรวจจริง (RISK-64) |
| `backend/app/Models/Saraban/Policy.php` · `Services/Saraban/PolicyCenter.php` | นโยบาย `signature` |
| `backend/config/module_registry.php` · `database/seeders/PermissionCatalogSeeder.php` | สิทธิ์เพิกถอนสองข้อ |
| `backend/database/seeders/OperationalPermissionSeeder.php` | ค่าตั้งต้นแยกผู้ขอ/ผู้ตัดสิน |
| `backend/routes/api.php` | 5 route |
| `backend/tests/Feature/SarabanSignatureVerificationTest.php` | **ใหม่** — 29 เทสต์ |
| `backend/tests/Feature/OperationalPermissionMatrixTest.php` | +2 เทสต์ค่าตั้งต้น |
| `frontend/src/components/saraban/DocumentTools.vue` | ผลตรวจ · ชุดหลักฐาน · ขอ/ตัดสินการเพิกถอน |
| `frontend/src/components/saraban/OperationsTools.vue` | คิวคำขอที่รอการตัดสิน |

## MIGRATIONS

- `2026_09_10_100000` — เพิ่ม 12 คอลัมน์ (**nullable ทั้งหมด**) ใน
  `saraban_signature_evidences` + สร้าง `saraban_signature_revocations` ·
  **ไม่แตะข้อมูลเดิม** · ลายเซ็นที่มีอยู่ได้ `signature_type = electronic` ตามค่าเริ่มต้น
- `2026_09_10_110000` — เดิน seeder สิทธิ์ · `down()` เป็น no-op โดยตั้งใจ

## TESTS

```
php artisan test         →  1,109 passed · 0 failed · 0 skipped (4,413 assertions)
./vendor/bin/pint --test →  PASS · 670 files
npm run lint / typecheck →  ผ่าน
npm test                 →  95 passed · 10 ไฟล์
saraban:concurrency-check --disposal →  ผ่าน · ไม่มีสถานะขัดกัน
```

**29 เทสต์ใหม่ ครอบคลุมทุกกรณีที่สั่ง:**

| ที่สั่ง | เทสต์ |
|---|---|
| valid signature | ✅ + ข้อที่ยืนยันว่า **ไม่มีใบรับรอง = ไม่รายงานผลใบรับรองเลย** |
| modified document after signing | ✅ + ไฟล์หาย |
| signer mismatch | ✅ |
| wrong document version | ✅ + แฮชไม่ตรงกับเวอร์ชันที่ผูกไว้ |
| invalid authority | ✅ |
| expired authority | ✅ (เป็นคำเตือน ไม่ใช่ความล้มเหลว) |
| delegated authority | ✅ |
| certificate expired / revoked | ✅ + ผู้ออกที่ไม่อยู่ในรายชื่อที่เชื่อถือ |
| invalid timestamp | ✅ |
| signing order invalid | ✅ |
| revoked document | ✅ (ตรวจว่า**ไม่ลบไฟล์**) |
| revoke rejected | ✅ (ตรวจว่าคำขอยังอยู่ในทะเบียน) |
| unauthorized verification detail access | ✅ |
| unauthorized revoke | ✅ + ผู้ไม่มีสิทธิ์ตัดสิน |
| re-auth required | ✅ (423 Locked) |
| verification API | ✅ + ชุดหลักฐาน |
| public verification privacy | ✅ ตรวจทั้งคีย์ที่ห้ามมี และสตริงที่ห้ามปรากฏ |
| regression existing signing flow | ✅ `SarabanSignatureAndStepUpTest` + `SarabanFinalizationAndVerificationTest` 28 ข้อ ไม่แตะ |

**บวกอีกสองข้อที่เพิ่มเพราะพบข้อผิดพลาดของตัวเองระหว่างทาง:** ผู้ขอเพิกถอน
อนุมัติคำขอตัวเองไม่ได้ · บทบาทที่อยู่ในตารางค่าตั้งต้นสองชุดต้องได้สิทธิ์ครบทั้งคู่

## SECURITY

| เรื่อง | ผล |
|---|---|
| ตรวจฝั่ง server เท่านั้น | ทุกสถานะตัดสินที่ service · หน้าจอแปลงรหัสเป็นคำเท่านั้น |
| ด่านรายวัตถุ | ทุก endpoint ผ่าน `DocumentPolicy::view()` ก่อนเสมอ |
| หน้าสาธารณะ | คืนสถานะย่อเท่านั้น · ตรึงด้วยเทสต์ว่าไม่มี `ip_address` `user_agent` `pdf_file_path` `document_hash` |
| step-up | ตรวจสองชั้น (route + service) เหมือนการลงนาม |
| separation of duties | ผู้ขอ ≠ ผู้ตัดสิน บังคับที่ server และแยกที่ค่าตั้งต้นของบทบาท |
| ไม่อ้างเกินจริง | ประกาศ `digital` โดยไม่มีใบรับรอง = `INVALID` ทันที |

## REGRESSION

โมดูลสารบรรณและระบบเดิมผ่านครบ · **ไม่มีเทสต์ใดเปลี่ยนจากผ่านเป็นล้ม** ·
เส้นทางการลงนามเดิมไม่ถูกแก้พฤติกรรมเลย — เพิ่มเพียงการบันทึกสองฟิลด์ตอนสร้างหลักฐาน

## UI-QA

**ไม่ได้ตรวจด้วยเบราว์เซอร์** ตามข้อจำกัดเดิมที่บังคับใช้อยู่ · หน้าจอทั้งหมด
**ใช้ส่วนประกอบเดิมของระบบ** (`AppButton` `AppInput` `AppSelect` `ConfirmOperation`
`v-can`) ไม่มีรูปแบบใหม่ · lint typecheck และเทสต์หน้าจอผ่านครบ

ผู้ดูแลควรตรวจสามจุดที่ `http://localhost:8001`:
1. หน้ารายละเอียดหนังสือ → "ตรวจสอบความน่าเชื่อถือของลายเซ็น"
2. ยื่นคำขอเพิกถอนด้วยบัญชีเจ้าหน้าที่ธุรการ → ตัดสินด้วยบัญชีผู้อำนวยการ
3. สแกน QR ของหนังสือที่ถูกเพิกถอน → ต้องขึ้นว่าใช้อ้างอิงไม่ได้

## EVIDENCE

- ชุดทดสอบ 1,109 ข้อ ผ่านครบโดยไม่มีข้อใดถูกข้าม
- ค่าตั้งต้นสิทธิ์บน dev: `Document Officer → saraban.signature.revoke` ·
  `Director → saraban.signature.revoke_approve` (และสิทธิ์ชั้นความลับยังอยู่ครบทั้งคู่)
- migration รันจริงบน MySQL แล้วทั้งสองตัว

## RISKS

| รหัส | เรื่อง | สถานะ |
|---|---|---|
| RISK-64 | หน้าสาธารณะไม่ได้ตรวจลายเซ็น | ✅ ปิด |
| RISK-66 | ไม่มีอะไรตั้ง `STATUS_CANCELLED` | ✅ ปิด |
| RISK-67 | **อำนาจย้อนหลังพิสูจน์ได้เท่าที่ snapshot บันทึกไว้** | เปิดไว้ · ต้องมีประวัติสิทธิ์แบบมีช่วงเวลาจึงจะแน่นกว่านี้ — เป็นการเปลี่ยนแกนกลางของระบบ |
| RISK-68 | ยังไม่มีลายเซ็นดิจิทัลจริง | เปิดไว้โดยตั้งใจ · โครงสร้างพร้อมแล้ว รอการตัดสินใจเรื่องผู้ให้บริการใบรับรอง |
| RISK-65 | `content_hash` ผนึกเฉพาะเมทาดาทา | ลดความรุนแรง · มี `integrity` ตรวจไฟล์แยกแล้ว |

## NEXT

1. **ทบทวนค่าตั้งต้นสิทธิ์เพิกถอน** ให้ตรงกับโครงสร้างจริงของหน่วยงาน
2. เมื่อจะใช้ลายเซ็นดิจิทัลจริง: เติม `trusted_certificate_issuers` และ
   `trusted_timestamp_authorities` ที่ศูนย์นโยบาย แล้วเขียนข้อมูลใบรับรองตอนลงนาม —
   **ไม่ต้องแก้โค้ดตรรกะการตรวจ**
3. เดิน UAT สามจุดในหัวข้อ UI-QA ด้วยบัญชีจริงของแต่ละบทบาท

---

## UI QA — ผลการตรวจด้วยเบราว์เซอร์จริง (2026-09-05)

รอบก่อนหน้าระบุว่า "ไม่ได้ตรวจด้วยเบราว์เซอร์" · รอบนี้ตรวจแล้วเท่าที่ทำได้โดย
**ไม่ต้องกรอกรหัสผ่าน** และ **พบข้อบกพร่องจริงสองข้อที่เทสต์ทั้ง 1,109 ข้อจับไม่ได้**

### สิ่งที่ตรวจได้ และตรวจไม่ได้

| ส่วน | ตรวจแล้ว | วิธี |
|---|---|---|
| หน้าตรวจสอบสาธารณะ (`/verify-saraban/:hash`) | ✅ เต็มรูปแบบ | เบราว์เซอร์จริง ไม่ต้องเข้าสู่ระบบ |
| หน้าจอภายใน (`DocumentTools`) | ✅ ระดับส่วนประกอบ | mount จริงด้วย vitest — เทสต์ 7 ข้อ |
| หน้าจอภายในในเบราว์เซอร์ | ❌ | ต้องเข้าสู่ระบบ ซึ่งผมกรอกรหัสผ่านไม่ได้ · ไม่มีเซสชันเดิมในเบราว์เซอร์ให้ใช้ |

### ข้อบกพร่องที่ 1 — หน้าตรวจสอบสาธารณะเปิดไม่ได้เลย

**เป็นข้อบกพร่องเดิมของระบบ ไม่ใช่ของงานนี้** — หน้าตรวจสอบใบลาซึ่งมีมาก่อน
ก็เสียเหมือนกัน

app shell เรียก `/api/settings` ตอนบูตทุกครั้ง ซึ่งเป็น endpoint ที่ต้องยืนยันตัวตน ·
ผู้ที่ยังไม่ได้เข้าสู่ระบบได้ 401 แล้ว interceptor ใน `axios.js` พาไปหน้าเข้าสู่ระบบ
**รวมถึงตอนที่เปิดหน้าตรวจสอบจาก QR ด้วย**

> ผลคือหน้าตรวจสอบทั้งของใบลาและของหนังสือราชการ **เปิดไม่ได้เลยสำหรับผู้ที่
> ถือกระดาษอยู่ในมือ** ซึ่งเป็นคนกลุ่มเดียวที่หน้านั้นมีไว้ให้ (SoT §12)

ยืนยันด้วยเบราว์เซอร์: ทั้ง `/verify-saraban/<hash>` และ `/verify-leave/<hash>`
เด้งไป `/login` ทันที

**แก้:** interceptor ไม่พาไปหน้าเข้าสู่ระบบเมื่ออยู่บนเส้นทางสาธารณะ ·
`PUBLIC_PATH_PREFIXES` ต้องตรงกับ route ที่ประกาศ `meta.public` ซึ่งมีเทสต์ตรึงไว้
ไม่ให้สองที่เดินหนีกัน

### ข้อบกพร่องที่ 2 — หน้าตรวจสอบยังขึ้นว่า "ถูกต้อง" ทั้งที่ไฟล์ถูกแก้

หลังแก้ข้อแรกจึงเห็นข้อนี้: หน้าจอไล่เงื่อนไขของตัวเองสามข้อ (เมทาดาทา · ยกเลิก ·
ยังไม่ออกฉบับสมบูรณ์) แล้ว **ตกลงมาที่ "เอกสารถูกต้องตามระบบ" เป็นค่าสุดท้าย**

เมื่อ server เริ่มตรวจแฮชไฟล์และการเพิกถอนในเช็คพอยต์นี้ หน้าจอจึงยังขึ้นว่าถูกต้อง
ทั้งที่ API ตอบ `is_valid: false` และ `verification_status:
DOCUMENT_MODIFIED_AFTER_SIGNING` อย่างถูกต้อง

> **หน้าจอที่ไล่เงื่อนไขเองจะตกยุคทุกครั้งที่ server ตรวจเพิ่ม** — และความล้มเหลว
> จะออกมาในรูปแบบที่อันตรายที่สุด คือบอกว่าผ่านทั้งที่ไม่ผ่าน

**แก้:** ข้อความมาจาก `verification_status` ที่ server ตัดสิน · สถานะที่ไม่รู้จัก
ถือว่า **ใช้อ้างอิงไม่ได้** ไม่ใช่ผ่าน · และไม่บอกคนทั่วไปว่าผิดพลาดที่จุดใด
เพราะเป็นข้อมูลภายใน

### หลักฐานจากเบราว์เซอร์

สร้างหนังสือทดสอบสองฉบับผ่านบริการจริงทุกขั้น (สร้าง → ลงนาม → ออกฉบับสมบูรณ์)
แล้วสับเปลี่ยนไฟล์ของฉบับที่สองหลังลงนาม

| ฉบับ | ก่อนแก้ | หลังแก้ |
|---|---|---|
| ลงนามถูกต้อง | เด้งไป `/login` | "เอกสารถูกต้องตามระบบ" (เขียว) |
| ไฟล์ถูกแก้หลังลงนาม | เด้งไป `/login` · เมื่อเปิดได้แล้วขึ้น "ถูกต้อง" | **"เอกสารนี้ถูกแก้ไขหลังการลงนาม" (แดง)** |

API ตอบถูกต้องตั้งแต่แรกทั้งสองกรณี — ปัญหาอยู่ที่ชั้นหน้าจอล้วน ๆ

**ลบข้อมูลทดสอบออกจากฐานพัฒนาแล้วครบทุกแถว** (หนังสือ เวอร์ชัน ไฟล์ หลักฐาน
ผู้ใช้ และหน่วยงาน)

### เทสต์ที่เพิ่ม

| ไฟล์ | ตรึงอะไร |
|---|---|
| `frontend/tests/publicVerificationAccess.test.js` | เส้นทางสาธารณะไม่ถูกพาไปหน้าเข้าสู่ระบบ · รายชื่อตรงกับ router · หน้าจอเชื่อ `verification_status` · สถานะที่ไม่รู้จัก = ใช้อ้างอิงไม่ได้ · ไม่รั่วรายละเอียดภายใน |
| `frontend/tests/sarabanSignatureVerification.test.js` | mount `DocumentTools` จริง 7 ข้อ — แสดงเหตุผลรายข้อจาก server · **ไม่มีใบรับรอง = ไม่มีหัวข้อใบรับรอง** · ไม่เรียกลายเซ็นอิเล็กทรอนิกส์ว่าดิจิทัล · สถานะไฟล์ถูกแก้ · คำขอเพิกถอน · บังคับกรอกเหตุผล · ชุดหลักฐาน |

### ผลรวม

```
php artisan test  →  1,109 passed · 0 failed · 0 skipped
pint --test       →  PASS 670 files
npm test          →  109 passed · 12 ไฟล์ (เพิ่ม 14 ข้อ)
lint / typecheck  →  ผ่าน
```

### ที่ยังต้องให้คนทำ

หน้าจอภายในในเบราว์เซอร์จริง — ต้องเข้าสู่ระบบ ซึ่งผมกรอกรหัสผ่านไม่ได้ ·
ขอให้ผู้ดูแลตรวจสามจุดที่ `http://localhost:8001`:

1. หน้ารายละเอียดหนังสือ → "ตรวจสอบความน่าเชื่อถือของลายเซ็น" → กด "ดูชุดหลักฐานฉบับเต็ม"
2. ยื่นคำขอเพิกถอนด้วยบัญชีเจ้าหน้าที่ธุรการ → ตัดสินด้วยบัญชีผู้อำนวยการ
3. คิวคำขอที่รอการตัดสินในหน้าตั้งค่า

> **หมายเหตุ:** `nginx` ที่พอร์ต 8001 เสิร์ฟ `frontend/dist` ที่ build ไว้ ·
> ต้อง `npm run build` ก่อนจึงจะเห็นการเปลี่ยนแปลงรอบนี้ · หรือใช้ dev server
> ที่พอร์ต 5173 ซึ่งเสิร์ฟซอร์สปัจจุบันอยู่แล้ว
