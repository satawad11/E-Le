# LEGACY-02C — Authentication & Session

วันที่: 2026-08-13
สถานะ: **PASS**

## ผลลัพธ์

- ล็อกบัญชี 15 นาทีเมื่อเข้าสู่ระบบผิดครบ 5 ครั้ง โดยใช้ key แบบ hash ของบัญชี
- กำหนด Sanctum token อายุเริ่มต้น 480 นาทีและเพิ่ม refresh endpoint ที่หมุน token เดิม
- revoke token ทั้งหมดทันทีเมื่อเปลี่ยนรหัสผ่านหรือระงับบัญชี รวมถึง route ระงับผู้ใช้ของผู้ดูแล
- เพิ่ม forgot/reset password flow และหน้า UI ภาษาไทย โดยตอบข้อความเดียวกันไม่ว่าอีเมลจะมีบัญชีหรือไม่
- บังคับรหัสผ่านใหม่ขั้นต่ำ 12 ตัวอักษรใน auth, profile และ user administration
- production Docker defaults ใช้ session encryption, secure cookie และ token expiry ส่วน local กำหนดผ่าน `.env`

## Verification

- Targeted backend: 12 tests / 31 assertions ผ่าน
- Authentication regression ครอบคลุม lockout, expiry, rotation, revoke, reset และ account-enumeration resistance
- Laravel Pint ผ่านสำหรับไฟล์ที่เกี่ยวข้อง
- Frontend production build ผ่าน 879 modules

## API

- เพิ่ม `POST /api/forgot-password` (throttle 5/minute)
- เพิ่ม `POST /api/reset-password` (throttle 5/minute)
- เพิ่ม `POST /api/refresh-token` สำหรับผู้ใช้ที่ authenticated (throttle 10/minute)

## Migration / Rollback

- ไม่มี database migration
- ย้อนกลับได้ด้วย `git revert`; token ที่ถูก revoke แล้วจะไม่ถูกกู้คืน
