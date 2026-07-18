# Existing System Audit (Pre-Phase 0)

## 1. ผลการตรวจสอบโครงสร้างระบบเดิม (Pre-Phase 0 Checklist)

- **โครงสร้างตาราง users, roles, permissions:** 
  - ระบบเดิมได้มีการ migrate เป็น Spatie `laravel-permission` เรียบร้อยแล้ว (อ้างอิงจาก `2026_07_13_180000_migrate_roles_to_spatie.php` และ `2026_07_12_003427_create_permission_tables.php`)
  - โครงสร้างผู้ใช้หลักอยู่ในตาราง `users` และมี `user_profiles` สำหรับข้อมูลเพิ่มเติม

- **ตาราง org chart/approval hierarchy:**
  - มีโครงสร้างตาราง `departments` (และมีการเพิ่ม `head` ให้กับแผนกแล้วผ่าน `2026_06_20_082600_add_head_and_status_to_departments_table.php`)
  - มีการจัดเก็บขั้นตอนการอนุมัติในตาราง `approval_steps` (`2026_06_14_000001_create_approval_steps_table.php`) และตารางเกี่ยวกับการมอบหมายงาน `user_delegations`

- **Auth guard ที่ Vue SPA ใช้เรียก API:**
  - ใช้ **Sanctum** ในการยืนยันตัวตน เนื่องจาก API routes ถูกป้องกันด้วย middleware `auth:sanctum` ในไฟล์ `routes/api.php`

- **Routing convention เดิม:**
  - ระบบเดิมไม่ได้ใช้ versioning แบบ `/api/v1/*` แต่เรียก API โดยตรงภายใต้ prefix `/api/` เช่น `/api/leaves`, `/api/users` 
  - ดังนั้นโมดูลสารบัญใหม่จะต่อด้วย `/api/saraban/...` เพื่อให้สอดคล้องกับระบบเดิม

- **Vue project structure:**
  - โครงสร้าง Frontend ไม่ได้จัดแบ่งแบบ `src/modules/*` อย่างที่เข้าใจในตอนแรก (พบว่าหน้าทั้งหมดรวมกันอยู่ในโฟลเดอร์ `src/views/` เช่น `DashboardView.vue`, `ApprovalsView.vue`)
  - สำหรับโมดูลใหม่สารบัญ อาจจะต้องพิจารณาว่าจะจัดเก็บไว้ที่ `src/views/saraban/` เพื่อให้สอดคล้องกับสไตล์โครงสร้างแบบเดิมแทนที่จะเป็น `src/modules/saraban/` 

---

## 2. สรุปคำตอบสำหรับ Open Questions (หัวข้อ 8 ใน CLAUDE.md)

1. **[รอการยืนยัน] ใช้ปีงบประมาณ หรือปีปฏิทิน พ.ศ. ในการรีเซ็ต running number?**
   - *คำถามต่อผู้ใช้:* วิทยาลัยใช้ปีอะไรในการขึ้นเลขรันนิ่งหนังสือราชการ (เช่น วันที่ 1 ม.ค. หรือ 1 ต.ค.)? (ในระบบ Leave เดิมมี `fiscal_years` ด้วย)

2. **[ตอบแล้ว] Leave System มี org chart/approval hierarchy อยู่แล้วหรือไม่ (ชื่อตาราง?)**
   - มีอยู่แล้ว สามารถ reuse โครงสร้างจากตาราง `departments` (สำหรับการเชื่อมโยงฝ่าย) และอ้างอิงรูปแบบจาก `approval_steps` หรือ `user_delegations` ได้เพื่อไม่ให้ซ้ำซ้อน

3. **[ตอบแล้ว] มี Auth guard/token structure แบบไหน (Sanctum? Passport? JWT?)**
   - ใช้ Laravel Sanctum (Token-based) เป็น Auth Guard

4. **[รอการยืนยัน] ต้องการ OCR ค้นหาเนื้อหาสแกน หรือพอแค่ full-text บน metadata/เนื้อหาที่พิมพ์ในระบบ?**
   - *คำถามต่อผู้ใช้:* ใน Phase 1-4 จำเป็นต้องมีระบบสกัดข้อความจากรูปภาพ (OCR) เพื่อนำมาใช้ค้นหาหรือไม่ หรือว่าค้นหาเฉพาะจากหัวข้อ/เรื่อง/metadata ที่กรอกลงในฟอร์มก็เพียงพอแล้ว?

5. **[รอการยืนยัน] ต้องการเชื่อม LINE Notify/LINE OA สำหรับแจ้งเตือนหรือไม่ (Leave มี integration นี้อยู่แล้วหรือไม่)**
   - จากการตรวจสอบพบว่าระบบ Leave เดิมมีการใช้ **Telegram** (`telegram_chat_id` ในตาราง `users`) ไม่ใช่ LINE
   - *คำถามต่อผู้ใช้:* ระบบสารบัญอิเล็กทรอนิกส์ต้องการแจ้งเตือนผ่าน Telegram เหมือนระบบลาเดิม หรือต้องการเพิ่มการแจ้งเตือนแบบ LINE Notify?

---
> **สำหรับผู้ใช้:** กรุณาตรวจสอบเอกสารฉบับนี้ และตอบคำถามที่ยัง [รอการยืนยัน] อยู่ เมื่อได้ข้อสรุปครบถ้วนแล้ว จึงจะเริ่มดำเนินการเขียนโค้ดใน Phase 1 ต่อไปครับ
