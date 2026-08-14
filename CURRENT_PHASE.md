# CURRENT PHASE

## Mode

`LEGACY MODERNIZATION`

ใช้โหมดนี้เมื่อ repository มีระบบเดิมอยู่แล้ว

## Current Phase

`CORE CHECKPOINT — PASS · อนุมัติแล้ว 2026-08-14`

> **ยังไม่เริ่ม LEGACY-06 และยังไม่ทำ Workflow Cutover — รอคำสั่ง**
>
> ตรวจเมื่อ 2026-08-14 · **P0 = 0 · P1 = 0** · เงื่อนไขครบทุกข้อ · เจ้าของระบบอนุมัติแล้ว
> รายงาน: `CORE_CHECKPOINT_REPORT.md`
>
> เฟสถัดไปที่เปิดให้ทำได้: **LEGACY-06 Time Foundation**
> สิ่งที่ยังห้ามทำจนกว่าจะมีคำสั่งแยก: Workflow Cutover (05-4)
>
> Workflow engine เดินคู่ขนานอยู่ ตรรกะเดิมยังเป็น source of truth
> การ cutover (05-4) เป็นขั้นตอนแยกที่ต้องได้รับอนุมัติ — ดู `docs/checkpoints/LEGACY-05-REPORT.md` ข้อ 11

| เฟส | สถานะ |
|---|---|
| LEGACY-ASSESSMENT | ✅ เสร็จ · ผู้ใช้อนุมัติในหลักการ 2026-08-12 |
| LEGACY-00 Freeze & Baseline | ✅ เสร็จ 2026-08-12 · ไม่มี FAIL · `docs/checkpoints/LEGACY-00-REPORT.md` |
| LEGACY-01 Stabilization | ✅ เสร็จ 2026-08-13 |
| LEGACY-02 Security Hardening | ✅ เสร็จ 2026-08-13 · 195 tests / 485 assertions · `docs/checkpoints/LEGACY-02-REPORT.md` |
| LEGACY-03 Core Data Foundation | ✅ เสร็จ 2026-08-13 · 204 tests / 506 assertions · `docs/checkpoints/LEGACY-03-REPORT.md` |
| LEGACY-04 RBAC + ABAC | ✅ เสร็จ 2026-08-14 · 268 tests / 693 assertions · `docs/checkpoints/LEGACY-04-FINAL-REPORT.md` (baseline: `LEGACY-04-REPORT.md`) |
| LEGACY-05 Workflow Engine | ✅ เสร็จ 2026-08-14 · 320 tests / 832 assertions · shadow ตรง 100% · **ยังไม่ cutover** · `docs/checkpoints/LEGACY-05-REPORT.md` |
| **CORE CHECKPOINT** | ✅ **PASS · อนุมัติแล้ว** 2026-08-14 · P0 = 0 · P1 = 0 · `CORE_CHECKPOINT_REPORT.md` |

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
