# CURRENT PHASE

## Mode

`PRODUCTION PHASE`

ใช้โหมดนี้สำหรับเตรียมและปรับใช้ระบบเข้าสู่ Production

## Current Phase

`PROD-00 Architecture Freeze`

> **2026-10-01** — ปิด DC-01..06 ของศูนย์จัดการเอกสาร ย้ายศูนย์ปฏิบัติการเป็น `system.*` และถอดโมดูลสารบรรณ V1.2
> backend 1,096 passed · frontend 135 passed · `docs/checkpoints/DOC-CENTER-FIX-AND-SARABAN-REMOVAL-2026-10-01.md`
> ค้าง: รัน migration drop บน production หลังสำรอง (`SARABAN_DROP_CONFIRMED`), ตรวจ PROD-02 ซ้ำบน production

> เสร็จเมื่อ 2026-08-15 · 456 tests / 1,328 assertions · Pint 452 files · frontend build 879 modules
> `docs/checkpoints/LEGACY-12-REPORT.md` · `docs/checkpoints/LEGACY-MODERNIZATION-FINAL-REPORT.md`
> **LEGACY-00 ถึง LEGACY-12 = FINAL PASS** · หยุดตามคำสั่ง

> **หยุดรอคำสั่งก่อนเริ่ม LEGACY-08 Attendance Integration**
>
> เสร็จเมื่อ 2026-08-15 · 402 tests / 993 assertions · `docs/checkpoints/LEGACY-07-REPORT.md`
> โมดูลลาเชื่อมกับ Core ใหม่ครบแล้ว · ไม่มี migration ใหม่ · ของเดิมที่ดีอยู่แล้วยังอยู่ครบ
>
> ค่าที่เจ้าของระบบยืนยันไว้ (LEGACY-06): grace period = 0 · เวลาราชการ 08:30–16:30 จันทร์–ศุกร์
>
> สิ่งที่ยังห้ามทำจนกว่าจะมีคำสั่งแยก:
> - Workflow Cutover (05-4)
> - เปิดใช้กะข้ามวันจริง — ต้องตัดสินก่อนว่าการลงเวลาหลังเที่ยงคืนนับเป็นของวันไหน
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
| LEGACY-06 Time Foundation | ✅ เสร็จ · **อนุมัติแล้ว** 2026-08-15 · 372 tests / 952 assertions · `docs/checkpoints/LEGACY-06-REPORT.md` |
| LEGACY-07 Leave Modernization | ✅ เสร็จ 2026-08-15 · 402 tests / 993 assertions · `docs/checkpoints/LEGACY-07-REPORT.md` |
| LEGACY-08 Attendance Integration | ✅ PASS 2026-08-15 · 428 tests / 1056 assertions · raw/result separation · vendor connector deferred by requirement · `docs/checkpoints/LEGACY-08-REPORT.md` |
| LEGACY-09 Overtime | ✅ PASS 2026-08-15 · 435 tests / 1096 assertions · approved/actual/certified separated · no compensation calculation · `docs/checkpoints/LEGACY-09-REPORT.md` |
| LEGACY-10 Notification Center | ✅ PASS 2026-08-15 · 442 tests / 1140 assertions · In-App/Email + optional LINE OA/Telegram adapters · `docs/checkpoints/LEGACY-10-REPORT.md` |
| LEGACY-11 Evaluation & Reporting | ✅ PASS 2026-08-15 · 448 tests / 1186 assertions · scope-safe reporting/export + raw evaluation metrics · `docs/checkpoints/LEGACY-11-REPORT.md` |
| LEGACY-12 Future Module Readiness | ✅ FINAL PASS 2026-08-15 · 456 tests / 1328 assertions · Module Registry/Contract + safe disable · `docs/checkpoints/LEGACY-12-REPORT.md` |
| **UX STABILIZATION (UX-01 ถึง UX-12)** | ✅ **FINAL PASS 2026-08-15** · 462 tests / 1370 assertions · frontend build 887 modules · 0 critical · `docs/checkpoints/UX-12-FINAL-REPORT.md` |
| **APPROVAL WORKFLOW AUDIT & FIX** | ✅ **FINAL PASS 2026-08-16** · 9/9 tests passed (44 assertions) · `docs/checkpoints/APPROVAL-WORKFLOW-FIX-REPORT.md` |

**Architecture Decision (อนุมัติแล้ว 2026-08-12):** คงเทคโนโลยีเดิม Laravel + Vue 3 + MySQL 8 + Tailwind + Docker
เอกสาร Target Architecture ตีความเป็น Architectural Principles และ Engineering Standards ไม่ใช่คำสั่งให้ rewrite stack

**Baseline ปัจจุบัน:** commit `6139212` · tag `baseline/legacy-2026-08-12` (ยังไม่ push ขึ้น origin)

## Allowed Work

- Pin dependency versions (PHP, Node, Composer, NPM)
- Document architecture and decisions
- Freeze database schemas
- Setup production environments

## Forbidden Work

- Feature development
- Schema modifications (unless critical bug fix)
- Unapproved dependency upgrades

## Exit Condition

- Dependencies pinned
- Architectures documented
- Schemas frozen
- `.env.example` ready for production

> LEGACY-07-2 completion addendum (2026-08-14): `LeaveController` responsibility split completed; no migration/schema/workflow cutover; full backend suite and frontend build pass. See `docs/checkpoints/LEGACY-07-REPORT.md` section 12.

> LEGACY-08 completion (2026-08-15): attendance sources/devices/raw events, idempotent reconciliation, scoped access, and workflow-based correction are active. Existing `attendances` remains the compatible daily result table; no vendor-specific connector was invented.

> LEGACY-09 completion (2026-08-15): OT orders, assignments, actual time, and certified time are separate domain records. Legacy attendance OT columns are read-only compatibility data; remuneration is intentionally out of scope.
