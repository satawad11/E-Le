# CURRENT PHASE

## Mode

`LEGACY MODERNIZATION`

ใช้โหมดนี้เมื่อ repository มีระบบเดิมอยู่แล้ว

## Current Phase

`LEGACY-ASSESSMENT`

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
