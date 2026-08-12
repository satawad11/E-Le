# LEGACY-02A — File Security

วันที่: 2026-08-13
สถานะ: **PASS**

## ผลลัพธ์

- ยืนยันว่า upload เดิมทั้งหมดใช้ private disk และ `/api/storage/{path}` ถูกถอดแล้วจาก security hotfix ก่อนหน้า
- ปิด nginx direct access ด้วย `location ^~ /storage { return 404; }` และยืนยัน config ด้วย `nginx -t`
- เพิ่ม authorization รายไฟล์ให้ `/api/files/{path}` ตามเจ้าของหรือสิทธิ์ของ resource และ deny by default สำหรับไฟล์กำพร้า
- ตรวจ selfie ลงเวลาด้วย MIME จาก bytes จริง รองรับเฉพาะ JPEG/PNG/WebP จำกัด 5 MB และตั้งชื่อด้วย UUID
- ใช้ validation เดียวกันทั้ง check-in และ check-out

## หลักฐาน test-first

ก่อนแก้:

- ไฟล์ PHP ที่ปลอมเป็น PNG ได้ HTTP 200
- payload เกิน 5 MB ได้ HTTP 200
- ผู้ใช้คนอื่นเปิดรูปโปรไฟล์และภาพลงเวลาได้ HTTP 200
- ไฟล์กำพร้าที่รู้ path เปิดได้ HTTP 200

หลังแก้ targeted suite ผ่าน 7 tests / 24 assertions และไม่มีไฟล์ผิดรูปแบบถูกเขียนลง storage

Full regression ผ่าน 182 tests / 430 assertions, Pint 300 files, Composer audit 0 advisory และ frontend build 877 modules

## ขอบเขตไฟล์ที่ authorization รองรับ

- รูปโปรไฟล์และลายเซ็นบุคลากร
- ภาพ check-in/check-out
- เอกสารแนบและลายเซ็นใบลา
- เอกสารแนบสารบรรณ โดยใช้ `DocumentPolicy::download`
- โลโก้องค์กร/ตราครุฑที่อ้างจาก system settings

## Migration/API

- ไม่มี database migration
- API เดิมคงอยู่: `GET /api/files/{path}` แต่เพิ่ม resource authorization
- direct `/storage/*` ตอบ 404 เสมอ

## Rollback

เป็น code/config-only change; ย้อนด้วย `git revert` ได้ ไม่มีการย้ายหรือลบไฟล์ข้อมูลในรอบนี้
