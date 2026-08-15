# Module Extension Guide

เอกสารนี้เป็นแนวทางเพิ่มโมดูลใหม่หลัง LEGACY-12 โดยไม่แก้ Core ซ้ำและไม่ใช้เมนูแทน authorization

## 1. Boundary

โมดูลใหม่เก็บ controller, service, model, policy, workflow adapter และ tests ภายใต้ namespace ของโมดูลเอง. โมดูลเรียก Core ผ่าน public contracts/services ได้ แต่ Core ห้าม import class จากโมดูลธุรกิจ.

Finance, Procurement, Asset, Planning & Budget, Vehicle Booking, Room Booking, Maintenance และ Meeting เป็นเพียงตัวอย่างทิศทางในอนาคต เอกสารนี้ไม่ได้กำหนด schema, workflow หรือ business rule ให้โมดูลเหล่านั้น.

## 2. Manifest

ลงทะเบียน manifest ใน `config/module_registry.php` และปฏิบัติตาม `ModuleContract`. ทุก manifest ต้องประกาศ:

- `code`, `name_th`, `name_en`, semantic `version`, `enabled`, `dependencies`, `order`
- `routes` และ `route_files`
- `menus`, `permissions`, `policies`
- `workflow_definitions`, `settings`, `notifications`, `reports`, `audit_events`
- optional service `providers` และ `route_patterns` สำหรับ safe disable

Permission ใหม่ใช้รูปแบบ `module.resource.action` เท่านั้น เช่น `asset.item.view`. ชื่อ permission เดิมที่ไม่ตรงมาตรฐานเป็น compatibility exception ของโมดูลเดิมและห้ามนำไปเป็นตัวอย่าง.

## 3. Routes and authorization

Route ใหม่ควรอยู่ในไฟล์ route ของโมดูลและประกาศผ่าน `route_files`. ทุก endpoint ต้องมี authentication, permission/policy และ organization/resource scope ของตนเอง. Menu visibility ไม่ให้สิทธิ์ backend.

กำหนด `route_patterns` ให้ครอบคลุม route surface ของโมดูล. เมื่อ module หรือ dependency ถูกปิด middleware จะตอบ 404 และ `MenuService` จะไม่ส่งเมนูของโมดูลนั้น.

## 4. Database migrations

- migration เป็น additive และอยู่ในลำดับเวลาเดียวกับ Laravel migrations
- ใช้ prefix/table names ที่สื่อ module และสร้าง index/foreign key ตาม query จริง
- ห้ามแก้ migration ที่เคย deploy; ใช้ forward migration
- migration ต้องไม่ assume ว่าโมดูลธุรกิจอื่นเปิดอยู่ เว้นแต่ประกาศ dependency ไว้
- ต้องทดสอบ fresh migration, upgrade บน MySQL 8, rollback ที่ไม่ทำลายข้อมูลร่วม และบันทึก backfill/checksum เมื่อมีข้อมูลเดิม

## 5. Enable/disable and deployment

ใช้ `MODULE_<CODE>_ENABLED` เป็น deployment switch. ปิด dependency จะทำให้ dependant ปิดตามแบบ transitive. Provider ยังลงทะเบียน container adapter เพื่อให้งาน queue/in-flight resolve ได้อย่างปลอดภัย แต่ route files, route access และ menus ใช้ effective enabled state.

ก่อนเปิดโมดูลใหม่ต้อง:

1. deploy code และ migrations
2. seed permissions/workflow/templates แบบ idempotent
3. มอบ permission + scope
4. smoke test routes, menu, audit และ health
5. เปิด module flag
6. monitor errors, queue, slow queries และ audit coverage

## 6. Required tests

- manifest validation, unknown/circular dependency
- enabled/disabled route and menu behavior
- permission/policy/scope/IDOR independent from menu
- workflow version pinning and audit, when applicable
- migration on fresh and upgraded database
- notification/report registration contracts
- full backend regression, Pint and frontend production build
