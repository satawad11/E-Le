# LEGACY UPGRADE START PROMPT

คัดลอก Prompt ด้านล่างไปใช้กับ Claude Code หรือ Codex ใน repository ของระบบเดิม

---

ฉันมีระบบเดิมที่พัฒนาและใช้งานอยู่แล้ว และต้องการปรับปรุงระบบเดิมให้เข้าสู่ Target Architecture ตามเอกสาร E-la Development Documentation Pack ที่อยู่ใน repository นี้

## กฎสูงสุด

- ห้ามสร้างระบบใหม่จากศูนย์
- ห้าม rewrite ทั้งระบบโดยพลการ
- ห้ามแก้ Source Code ในรอบแรก
- ห้าม Drop/Rename Database โดยไม่มี Migration Plan
- ห้ามลบข้อมูลเดิม
- ห้ามเปลี่ยน Authentication/Authorization โดยไม่มี Compatibility และ Rollback Plan
- ให้ Reuse สิ่งที่ดี, Refactor สิ่งที่แก้ได้, Replace เฉพาะสิ่งที่จำเป็นจริง

## ขั้นตอนที่ 1 — อ่านเอกสาร

อ่านอย่างน้อย:

- START-HERE.md
- AGENTS.md
- CLAUDE.md
- PROJECT_RULES.md
- CURRENT_PHASE.md
- TASKS.md
- docs/00-MASTER-BLUEPRINT.md
- docs/01-ARCHITECTURE.md
- docs/04-DATABASE-STANDARDS.md
- docs/05-SECURITY-STANDARDS.md
- docs/06-RBAC-ABAC.md
- docs/07-WORKFLOW-ENGINE.md
- docs/10-TESTING-STANDARDS.md
- docs/15-INTEGRATION-STANDARDS.md
- docs/18-DEFINITION-OF-DONE.md
- docs/REQUIREMENTS-MATRIX.md
- docs/TRACEABILITY-MATRIX.md
- docs/LEGACY-SYSTEM-ASSESSMENT.md
- docs/LEGACY-GAP-ANALYSIS.md
- docs/LEGACY-MIGRATION-PLAN.md

## ขั้นตอนที่ 2 — ตรวจระบบเดิมจากของจริง

ตรวจ:

- repository structure
- framework/runtime
- package/dependency
- database schema/migrations
- authentication
- authorization
- users/personnel
- organization structure
- workflow/approval
- leave
- attendance
- overtime
- notification
- reports
- file storage
- device/vendor integrations
- API/middleware
- validation
- audit logging
- tests
- Docker/deployment
- backup/restore

สำหรับเครื่องสแกน ให้ตรวจว่าโครงสร้างปัจจุบันเป็นอย่างไร โดยเฉพาะกรณี:

เครื่องสแกนใบหน้า/ลายนิ้วมือ → ระบบของผู้ผลิต → ระบบเดิม

ให้ระบุวิธีรับข้อมูล เช่น API, webhook, database, file, polling และ identifier ที่ใช้ mapping บุคลากร

## ขั้นตอนที่ 3 — ห้ามแก้โค้ด

ในรอบแรกให้สร้างและกรอกข้อมูลจริงในไฟล์:

1. docs/LEGACY-SYSTEM-ASSESSMENT.md
2. docs/LEGACY-GAP-ANALYSIS.md
3. docs/LEGACY-MIGRATION-PLAN.md

อย่าใส่ข้อความทั่วไป ให้ระบุหลักฐานจาก Source Code/Database จริง เช่น path, table, endpoint หรือ module

## ขั้นตอนที่ 4 — สรุปให้ฉันตรวจ

รายงาน:

- Current architecture
- Current technology stack
- สิ่งที่ควร KEEP
- สิ่งที่ควร REFACTOR
- สิ่งที่ควร REPLACE
- Critical/High security findings
- Database risks
- Data migration requirements
- Backward compatibility risks
- Top 10 gaps
- Recommended migration sequence

จัดลำดับ Priority เป็น P0/P1/P2/P3

## ขั้นตอนที่ 5 — หยุด

หลังสร้าง Assessment + Gap Analysis + Migration Plan แล้ว ให้หยุด

ห้ามแก้ Source Code
ห้ามสร้าง Migration
ห้ามเริ่ม CORE-01
ห้ามเริ่ม e-Leave/Attendance/OT

รอคำสั่งฉันก่อนเริ่ม Refactor

เริ่มตรวจ Repository ปัจจุบันได้เลย
