# CORE CHECKPOINT

Do not proceed to Time/Leave until all pass.

**ตรวจเมื่อ 2026-08-14 · ผล: ✅ PASS · อนุมัติแล้วโดยเจ้าของระบบ 2026-08-14**
รายงานฉบับเต็มพร้อมหลักฐาน: [`CORE_CHECKPOINT_REPORT.md`](../../CORE_CHECKPOINT_REPORT.md)

- [x] Organization hierarchy/effective history correct — `org_units` + `CoreDataFoundationTest`
- [x] Personnel types configurable; assignment history preserved — `personnel_assignments` effective-dated
- [x] Authentication/session controls pass — `AuthenticationHardeningTest`
- [x] RBAC+ABAC deny-by-default — `PermissionEvaluatorTest`
- [x] Cross-user/cross-unit IDOR tests pass — 12 test class ครอบ authz/IDOR
- [x] Workflow versioning and actor resolution pass — Leave (`WorkflowEngineTest`) + Saraban (`SarabanWorkflowPinningTest`)
- [x] Delegation/acting approval auditable — actor / effective actor / delegated_by ครบใน `workflow_actions`
- [x] Dynamic module/menu works without becoming security boundary — `RouteAuthorizationAuditTest`
- [x] Audit contains required mutation records — `CentralAuditLogTest` + append-only enforcement
- [x] Migrations, seeds, tests, lint, typecheck, build pass — Pint 362 files · build ผ่าน · migrate จากฐานว่างบน MySQL 8 ครบ 103 ตัว
- [x] No critical/high unresolved security issue — P0 = 0 · `composer audit` สะอาด

## เงื่อนไขผ่านตาม LEGACY-MIGRATION-PLAN

- [x] P0 gaps = 0 — **16/16 ปิดครบ**
- [x] P1 gaps = 0 — **18/18 ปิดครบ** (รอบแรกเหลือ 3 · แก้ครบเมื่อ 2026-08-14)
- [x] Authorization test matrix + IDOR test ผ่านครบ
- [x] Backup + restore drill สำเร็จ — 73 ตาราง · mismatch 0
- [x] Data reconciliation ผ่าน — ส่วนต่างอธิบายได้ครบ · ไม่มีตารางใดลดลง
- [x] `docs/TRACEABILITY-MATRIX.md` อัปเดตครบ
- [x] สร้าง `CORE_CHECKPOINT_REPORT.md` และ**ได้รับอนุมัติ** — อนุมัติเมื่อ 2026-08-14

**เงื่อนไขครบทุกข้อ — เริ่ม Phase 06 ขึ้นไปได้**
(ยังไม่เริ่ม — รอคำสั่ง · Workflow Cutover ยังห้ามทำ ต้องมีคำสั่งแยก)

Output required: `CORE_CHECKPOINT_REPORT.md` with evidence and test commands. ✅
