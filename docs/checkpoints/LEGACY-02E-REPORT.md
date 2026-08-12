# LEGACY-02E — General Hardening

วันที่: 2026-08-13
สถานะ: **PASS (targeted verification)**

## ผลลัพธ์

- ถอด `SanitizeInput` ที่แก้ payload ทั่วระบบแบบเงียบ และคง validation ราย endpoint
- กำจัด `v-html` ทั้ง 2 จุด: pagination แสดง text และ preview แม่แบบอยู่ใน sandboxed iframe ที่ไม่อนุญาต script
- เพิ่ม CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy และ Permissions-Policy ทั้ง Laravel และ nginx; HSTS ส่งเมื่อ request เป็น HTTPS
- เปลี่ยน internal exception ของ Saraban create/update/sign เป็นข้อความไทยมาตรฐานและเก็บรายละเอียดไว้ใน server log
- เพิ่ม throttle 10/minute ให้ export/download สำคัญ และ 30/minute ให้ secure file streaming
- เพิ่ม automated route audit ที่บังคับ `auth:sanctum` กับ API ทุกเส้นนอก public allowlist

## Verification

- Test-first SecurityHeadersTest: ก่อนแก้ไม่พบ header; หลังแก้ผ่าน 1 test / 8 assertions
- Security targeted suite ผ่าน 28 tests / 89 assertions
- RouteAuthorizationAuditTest ผ่านทั้ง authentication coverage และ specific export/download throttles
- `rg "v-html|SanitizeInput" backend frontend/src` ไม่มีผลลัพธ์
- nginx `nginx -t` ผ่าน
- frontend production build ผ่าน 879 modules

## Migration / API / Rollback

- ไม่มี database migration
- API response success เดิมไม่เปลี่ยน; error ภายในไม่เปิดเผย exception detail อีกต่อไป
- rollback ได้ด้วย `git revert`; การย้อน security headers ต้องประเมินความเสี่ยง clickjacking/XSS ก่อน
