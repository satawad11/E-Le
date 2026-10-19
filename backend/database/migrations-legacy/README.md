# migrations-legacy — migration ชุดเดิม (ไม่ถูกโหลด)

ย้ายมาจาก `database/migrations/` ใน R-02 (REBUILD-2569, ADR-R-002) — Laravel โหลดเฉพาะ `database/migrations/`
ไฟล์ทั้ง 141 ไฟล์เก็บไว้อ้างอิงเท่านั้น **ห้ามรันกับฐานข้อมูลของระบบใหม่**

- ระบบเดิมที่ใช้งานจริงยังรันชุดนี้อยู่บน tag `legacy-final` (ADR-R-004)
- baseline ใหม่ (`database/migrations/*_r02_*`) สร้างตารางโมดูลเดิมในรูปสุดท้ายของชุดนี้
  โดยแปลงจาก schema ของฐานข้อมูลทดสอบที่รันทุกไฟล์ที่นี่ครบ (ADR-R-011)
- ข้อมูลที่ migration ชุดนี้เคยใส่ (ประเภท/นโยบายการลา, ประเภท/แม่แบบการแจ้งเตือน) ย้ายไปเป็น
  `database/seeders/data/*.json` + `LeaveRegulationSeeder`, `NotificationCatalogSeeder`
