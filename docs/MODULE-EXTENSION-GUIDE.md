# วิธีเพิ่มโมดูลใหม่ (REBUILD-2569)

> เพิ่มโมดูลธุรกิจใหม่ได้หลังจบ R-09 เท่านั้น

1. สร้าง `backend/app/Modules/<Studly>/` พร้อม `manifest.php`, ServiceProvider, migrations, controllers
2. ประกาศ features/actions ให้ตรงกับสิ่งที่ผู้ใช้ทำจริง ใช้ชื่อไทยที่ผู้ดูแลอ่านเข้าใจ
3. ระบุ `requires` เมื่อ action หนึ่งต้องอาศัยอีก action (เช่น อนุมัติ ต้องดูได้)
4. ระบุ `risk: high` กับ action ที่แก้กติกา ตั้งค่า หรือข้อมูลการเงิน
5. ระบุ `rules: ['not_own']` กับ action อนุมัติ/ตรวจ
6. ประกาศ `templates` 2–4 ชุดที่ใช้บ่อย และ `defaults` ต่อระดับ L1–L5
7. ประกาศ `menus` และ permission ที่ทำให้เห็นแต่ละเมนู
8. ลงทะเบียนโมดูลใน `config/modules.php` แล้วรัน `php artisan modules:sync`
9. ผู้ดูแลตรวจค่าเริ่มต้นที่หน้า "ตั้งค่าสิทธิ์รายโมดูล" และมอบชุดสิทธิ์เฉพาะทาง
10. เพิ่มแถวใน Permission Matrix และให้เทสต์ persona ผ่าน

ตัวอย่างเต็ม: `backend/app/Modules/Leave/manifest.php` (สร้างใน R-08)
