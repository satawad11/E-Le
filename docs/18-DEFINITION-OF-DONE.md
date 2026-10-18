# DEFINITION OF DONE (REBUILD-2569)

งานหรือโมดูลเสร็จเมื่อครบทุกข้อ:

1. migration (ย้อนกลับได้) + seeder/fixture
2. API/service + FormRequest validation
3. `PermissionEngine::can` ทุก action และ `scopeQuery` ทุกรายการ · ไม่มีชื่อ role ในตรรกะ
4. การปิดบังฟิลด์ตามชั้นความลับ (ถ้าแตะข้อมูลบุคลากร)
5. audit ทุก mutation สำคัญ · log การเปิดดูข้อมูลอ่อนไหว
6. UI ภาษาไทย ตรงต้นแบบ ใช้ได้บนมือถือ · เมนูมาจาก manifest
7. เทสต์: business rule · persona จาก Permission Matrix · IDOR · กฎกลางที่เกี่ยวข้อง
8. `php artisan test`, `vendor/bin/pint --test`, `npm run build` ผ่าน
9. อัปเดต `TASKS.md`, `TRACEABILITY-MATRIX.md`, `CHANGELOG.md` (และ ADR ถ้ามี)
