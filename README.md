# E-Le — ระบบบริหารงานบุคคลสถานศึกษา

ระบบลาออนไลน์ ลงเวลา งานล่วงเวลา ศูนย์แจ้งเตือน ศูนย์รายงาน และศูนย์จัดการเอกสาร
สำหรับสถานศึกษาอาชีวศึกษา · Laravel 13 + Vue 3 + MySQL 8 (Docker)

| เริ่มที่ | เนื้อหา |
|---|---|
| `START-HERE.md`, `AGENTS.md`, `PROJECT_RULES.md`, `CLAUDE.md` | กติกาการพัฒนาและลำดับงาน |
| `CURRENT_PHASE.md`, `TASKS.md` | สถานะปัจจุบันและงานค้าง |
| `docs/00-MASTER-BLUEPRINT.md`, `docs/01-ARCHITECTURE.md` | ภาพรวมและสถาปัตยกรรม |
| `docs/modules/` | ข้อกำหนดรายโมดูล |
| `docs/CHANGELOG.md`, `docs/DECISION-LOG.md` | ประวัติการเปลี่ยนแปลงและการตัดสินใจ |
| `docs/archive/saraban-v1.2/` | เอกสารโมดูลสารบรรณที่ถอดออกแล้ว (เก็บถาวร) |

ตรวจคุณภาพก่อน push: `cd backend && composer verify` และ `cd frontend && npm run verify`
(hook `.githooks/pre-push` รันให้อัตโนมัติ)
