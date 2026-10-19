# MODULE STANDARDS (REBUILD-2569)

ทุกโมดูลต้องมี:

1. `backend/app/Modules/<Studly>/manifest.php` ตามรูปแบบ `REBUILD-PLAN-2569.md` §3.4 ประกอบด้วย
   `code`, `name`, `version`, `order`, `dependencies`, `features` (actions + `allowed scopes`, `requires`, `risk`, `rules`),
   `templates`, `defaults` (ต่อระดับ L1–L5), `menus` (ตามผัง §8), `workflows`, `notifications`, `audit_events`, `settings`
2. ServiceProvider ของโมดูลที่ลงทะเบียน route, policy, listener — ไม่แตะ core ของโมดูลอื่น
3. ทุก controller action เรียก `PermissionEngine::can` และทุก list เรียก `scopeQuery`
4. ทุกสายอนุมัติประกาศใน `workflows` ด้วย resolver ตามระดับหน่วย (CORE-05)
5. เทสต์สิทธิ์ที่สร้างจาก manifest + Permission Matrix และเทสต์ IDOR
6. ปิดโมดูลได้โดยไม่กระทบ core: เมนูหาย, API ตอบ 404, ข้อมูลคงอยู่

หลังแก้ manifest ต้องรัน `php artisan modules:sync` และ commit ผลเทสต์
