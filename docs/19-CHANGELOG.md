# CHANGELOG

## Unreleased
- Initial documentation pack created.

### LEGACY-02 Security Hardening

- ปิด LEGACY-02A: ตรวจ MIME/ขนาดภาพลงเวลาจาก bytes จริง, ใช้ UUID, บังคับ resource authorization สำหรับ private files และปิด `/storage` ที่ nginx
- ปิด LEGACY-02C: เพิ่ม account lockout, token expiry/refresh rotation, revoke token เมื่อระงับบัญชีหรือเปลี่ยนรหัสผ่าน, password reset ที่ไม่เปิดเผยบัญชี และ production session defaults ที่ปลอดภัย
- ปิด LEGACY-02D: บังคับรหัสผ่านทุกโหมดลงนาม, จำกัดผู้ลงนามตามผู้รับมอบหมาย, ผนึก document hash เพื่อตรวจ tampering และ throttle public verification
- ปิด LEGACY-02E: เพิ่ม security headers ที่ Laravel/nginx, ถอด global input sanitizer, กำจัด `v-html`, ซ่อน internal errors, throttle export/download และเพิ่ม automated route authorization audit

### LEGACY-01 Stabilization

- เพิ่ม automated backup พร้อม restore drill, queue worker และ scheduler
- เพิ่ม CI สำหรับ Pint, backend tests, fresh migration บน MySQL 8 และ frontend build
- เพิ่ม production debug guard, structured JSON logging และ request correlation ID
- แก้ LeaveController เมื่อผู้ใช้ไม่มี role และใช้ permission แทนชื่อ role
- เปลี่ยน `leaves.status` จาก ENUM เป็น `VARCHAR(20)` + CHECK เพื่อรองรับ `Voided`
- ลบ debug/test artifacts และอัปเดต Dompdf เป็น 3.1.6 เพื่อปิด security advisories
- เติมเมนู core 15 รายการด้วย idempotent seeder
- ปิด GATE-01 เมื่อ 2026-08-13: ใช้บัญชี `eleave_app` แบบจำกัดสิทธิ์, ตั้ง root password, ลบ remote root และยืนยัน backup หลัง restart
- เติมปีงบประมาณ 2567–2571 แบบ idempotent โดยกำหนดปี 2569 เป็นปีปัจจุบัน
- เติมวันหยุดราชการทั่วประเทศปี 2569 จำนวน 20 วัน (ไม่รวมวันหยุดเฉพาะ กทม. 16 ต.ค.) และลงทะเบียนแม่แบบใบลาใช้งานจริง 5 แบบจากเอกสารอ้างอิง
- แก้ Pint style issues 5 รายการใน 5 ไฟล์

## 2026-08-12 — Legacy Modernization Pack

- Added Legacy System Assessment
- Added Legacy Gap Analysis
- Added Legacy Migration Plan
- Added Legacy Upgrade Prompt
- Changed current phase to LEGACY-ASSESSMENT for existing-system upgrades
