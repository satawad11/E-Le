# LEGACY-02 — Security Hardening

วันที่: 2026-08-13
สถานะ: **PASS / COMPLETE**

## ขอบเขตที่เสร็จ

- LEGACY-02A File Security: private storage, MIME/size validation, UUID filenames, resource authorization และ nginx deny
- LEGACY-02B Authorization & IDOR: ปิดช่องโหว่ Leave, Saraban, settings และ attendance exports ตามรายงาน 02B เดิม
- LEGACY-02C Authentication & Session: account lockout, token expiry/rotation/revocation, password reset และ secure production session defaults
- LEGACY-02D E-Signature Integrity: password ทุก mode, assigned signer, sealed document hash, tampering detection และ public throttle
- LEGACY-02E General Hardening: security headers, safe HTML rendering, generic errors, export/download throttles และ route authorization audit

## Exit Criteria

- SEC-001 ถึง SEC-012 ที่อยู่ใน phase นี้มี implementation และ regression coverage
- private file endpoint ต้อง authentication และ resource authorization; orphan file ถูก deny by default
- cross-user IDOR tests ครอบคลุม Leave, Saraban documents/attachments, attendance images และ profile files
- RouteAuthorizationAuditTest ยืนยัน API ทุกเส้นนอก public allowlist มี `auth:sanctum`
- full backend regression ไม่มี failure

## Final Verification

- Backend: **195 tests / 485 assertions — PASS**
- Laravel Pint: **304 files — PASS**
- Composer audit: **0 security advisories**
- Frontend build: **879 modules — PASS**
- nginx configuration: **syntax valid**
- Migration `2026_08_13_000001_add_document_hash_to_saraban_document_signatures_table` รันสำเร็จ

## Commits

- `da7a054` — LEGACY-02A file hardening
- `609559b` — LEGACY-02C authentication hardening
- `c24de77` — LEGACY-02D signature integrity
- `340a42b` — LEGACY-02E general hardening
- LEGACY-02B อ้างอิง implementation/report จาก commit ก่อนหน้า

## ข้อจำกัดและการปฏิบัติการ

- ลายเซ็น Saraban เดิมที่ไม่มี `document_hash` จะ verify เป็น invalid เพราะพิสูจน์เนื้อหาต้นฉบับย้อนหลังไม่ได้
- ตั้งค่า `SESSION_SECURE_COOKIE=true` ใช้กับ production HTTPS; local `.env` คงปรับเป็น false ได้
- ต้องตั้งค่า mail transport จริงเพื่อส่ง password reset email ใน production
- ยังไม่เริ่ม LEGACY-03
