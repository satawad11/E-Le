# AGENTS.md — Codex/Agent Operating Contract

## Mission
พัฒนา E-la เป็นแพลตฟอร์มบริหารงานสถานศึกษาแบบ Modular Monolith ที่ปลอดภัย ขยายได้ และ UI ภาษาไทย 100%

## Mandatory reading order
ก่อนแก้โค้ดทุกครั้งให้อ่าน:
1. `PROJECT_RULES.md`
2. `CURRENT_PHASE.md`
3. `TASKS.md`
4. `docs/00-MASTER-BLUEPRINT.md`
5. เอกสารมาตรฐานที่เกี่ยวข้อง
6. เอกสารโมดูลที่กำลังพัฒนา

## Hard gates
- ห้ามเริ่มโมดูลหลัง CORE ก่อน `CORE-CHECKPOINT` ผ่าน
- ห้าม hard-code policy, workflow, menu, organization name หรือ personnel type
- ห้ามเชื่อ frontend authorization
- ทุก endpoint ต้องมี server-side authorization + scope check
- ทุก resource access ต้องป้องกัน IDOR
- ทุก mutation สำคัญต้อง audit
- ห้ามลบ historical/effective-dated records เพื่อแก้ข้อมูลย้อนหลัง
- ห้าม bypass tests เพื่อให้ CI ผ่าน

## Delivery contract
เมื่อจบ task ต้อง:
- รัน lint/typecheck/tests/build
- อัปเดต `TASKS.md`
- อัปเดต `docs/TRACEABILITY-MATRIX.md`
- อัปเดต `docs/CHANGELOG.md`
- บันทึก architectural decision ใหม่ใน `docs/DECISION-LOG.md` เมื่อมี
- ระบุไฟล์ที่เปลี่ยน, migration, API, test และข้อจำกัด

## Language
UI/copy ภาษาไทย 100%; code/schema/identifier ภาษาอังกฤษ
