# E-la Integrated Administration Platform

ชุดเอกสารพัฒนาระบบบริหารงานสถานศึกษาแบบ Modular สำหรับใช้ร่วมกับ Claude Code และ Codex

## เริ่มต้น
1. อ่าน `AGENTS.md` และ `CLAUDE.md`
2. อ่าน `PROJECT_RULES.md`
3. อ่าน `docs/00-MASTER-BLUEPRINT.md`
4. ตรวจ `CURRENT_PHASE.md` และ `TASKS.md`
5. พัฒนาเฉพาะ Phase ที่อนุญาต
6. หยุดที่ Checkpoint ก่อนเริ่ม Phase ถัดไป

## Stack
Next.js + TypeScript + PostgreSQL + Redis + Tailwind CSS + Docker + MinIO/S3 + Nginx

## หลักการ
Configuration over Hard-code, Policy-driven, Workflow-driven, Permission-driven, Event-driven, Audit everything, Secure by default, Modular by design.
