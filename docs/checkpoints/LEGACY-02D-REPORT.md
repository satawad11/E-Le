# LEGACY-02D — E-Signature Integrity

วันที่: 2026-08-13
สถานะ: **PASS**

## ผลลัพธ์

- บังคับยืนยันรหัสผ่านสำหรับการลงนามทุก mode โดย legacy setting ไม่สามารถปิดการยืนยันตัวตนได้
- จำกัดผู้ลงนามให้มีสิทธิ์ลงนาม อยู่ในสถานะ `approved_pending_signature` และเป็น receiver ที่ได้รับมอบหมายของเอกสาร
- เพิ่ม `document_hash` ในลายเซ็นเพื่อผนึกข้อมูลสำคัญของเอกสาร ณ เวลาลงนาม
- public verify คำนวณ hash ใหม่ด้วย canonical fields และคืน `is_valid: false` เมื่อเนื้อหาถูกแก้
- จำกัด verify และ QR endpoint ที่ 30 requests/minute

## หลักฐาน test-first

ก่อนแก้ targeted suite พบ 2 failures:

- เมื่อ legacy setting เป็น `0` สามารถลงนามโดยไม่ส่งรหัสผ่านและได้ HTTP 200
- เมื่อแก้ title หลังลงนาม endpoint verify ยังคืน `is_valid: true`

หลังแก้ `SignatureTest` ผ่าน 10 tests / 21 assertions รวม password ทุก mode, assigned signer, tampering detection และ classified-document privacy

## Migration / API

- เพิ่ม migration `document_hash` แบบ nullable ใน `saraban_document_signatures`
- ลายเซ็นเดิมที่ไม่มี sealed hash จะ verify เป็น invalid โดยตั้งใจ เพราะไม่สามารถพิสูจน์เนื้อหาต้นฉบับย้อนหลังได้
- API เดิมคงรูปแบบ response และเพิ่ม throttle เท่านั้น

## Rollback

ย้อน code และ migration ได้ด้วย `git revert` และ `php artisan migrate:rollback`; การ rollback จะทำให้การตรวจ tampering ของลายเซ็นใหม่หายไป
