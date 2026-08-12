# CURRENT PHASE

## Mode

`LEGACY MODERNIZATION`

ใช้โหมดนี้เมื่อ repository มีระบบเดิมอยู่แล้ว

## Current Phase

`LEGACY-00 COMPLETE — รออนุมัติเริ่ม LEGACY-01`

| เฟส | สถานะ |
|---|---|
| LEGACY-ASSESSMENT | ✅ เสร็จ · ผู้ใช้อนุมัติในหลักการ 2026-08-12 |
| LEGACY-00 Freeze & Baseline | ✅ เสร็จ 2026-08-12 · ไม่มี FAIL · `docs/checkpoints/LEGACY-00-REPORT.md` |
| LEGACY-01 Stabilization | ⛔ **ยังไม่อนุญาต** — รอคำตอบ D-1…D-4 และการอนุมัติ |

**Architecture Decision (อนุมัติแล้ว 2026-08-12):** คงเทคโนโลยีเดิม Laravel + Vue 3 + MySQL 8 + Tailwind + Docker
เอกสาร Target Architecture ตีความเป็น Architectural Principles และ Engineering Standards ไม่ใช่คำสั่งให้ rewrite stack

**Baseline ปัจจุบัน:** commit `6139212` · tag `baseline/legacy-2026-08-12` (ยังไม่ push ขึ้น origin)

## Allowed Work

- อ่าน Source Code และ Database schema
- Inventory ระบบเดิม
- Security assessment
- Gap analysis
- Migration planning
- กรอก `docs/LEGACY-SYSTEM-ASSESSMENT.md`
- กรอก `docs/LEGACY-GAP-ANALYSIS.md`
- กรอก `docs/LEGACY-MIGRATION-PLAN.md`

## Forbidden Work

- ห้ามแก้ Source Code ใน Assessment รอบแรก
- ห้าม Drop/Rename schema
- ห้ามลบข้อมูล
- ห้ามเริ่ม CORE-01 ถึง CORE-05
- ห้ามเริ่ม Leave/Attendance/OT

## Exit Condition

ต้องได้รับการตรวจและอนุมัติ Legacy Assessment + Gap Analysis + Migration Plan ก่อนเข้าสู่ Legacy Refactor Phase
