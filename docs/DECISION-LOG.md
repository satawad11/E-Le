# DECISION LOG

## ADR-USER-01: Additive Personnel Entity Separation & 1:1 Identity Binding

- **Status**: Accepted
- **Date**: 2026-08-16
- **Context**: 
  The `users` table previously served dual roles (Authentication/Identity Domain and Staff/Personnel Domain).
  68 Foreign Keys across 38 tables directly reference `users.id`.
- **Decision**:
  1. Separate Staff Identity into a new `personnel` table with UUID and 1:1 binding (`personnel.user_id` -> `users.id`).
  2. Retain `users.id` as the authoritative security/authentication identifier.
  3. Keep PII (national ID card number, address, signature) in `user_profiles` without redundant duplication.
  4. Perform idempotent backfill during additive migration.
  5. Do NOT change foreign keys in Leave, Attendance, OT, Saraban, Workflow, or Audit modules in this foundation phase.
- **Consequences**:
  - Full backward compatibility maintained.
  - Zero breaking changes to existing APIs and tokens.
  - Foundation ready for USER-02 (Dual-Write & Observer synchronization).

## ADR-USER-02: Field Ownership Matrix & Loop-Safe Dual-Write Synchronization

- **Status**: Accepted
- **Date**: 2026-08-16
- **Context**: 
  Separation of `users`, `personnel`, `user_profiles`, and `personnel_assignments` creates risk of data drift if mutations in one domain do not reflect in others.
  Conversely, naive bi-directional observers risk infinite event loops.
- **Decision**:
  1. Define a strict Field Ownership Matrix (users = auth, user_profiles = PII, personnel = staff identity, personnel_assignments = org/position).
  2. Implement `PersonnelSyncService` with thread-safe recursion guard `withoutSyncRecursion(string $key, callable $callback)` preventing circular observer triggers.
  3. Wrap all cross-table synchronizations in database transactions.
  4. Ensure effective-dating history is preserved when assignments change (close previous with yesterday's date, open new with today's date).
  5. Refactor model lifecycle hooks into dedicated Observers registered in `AppServiceProvider`.
- **Consequences**:
  - Zero data drift across all 4 entity tables.
  - Safe against infinite event recursion and partial database updates.
  - Ready for USER-03 (Service refactoring to read Personnel domain directly).

## ADR-USER-03: Core Services Refactoring to Personnel Domain

- **Status**: Accepted
- **Date**: 2026-08-16
- **Context**: 
  Core services previously read organizational units, personnel types, positions, and staff names directly from legacy fields in `users` (`department_id`, `org_unit_id`, `personnel_type_id`, `name`).
- **Decision**:
  1. Refactor `OrgScopeResolver`, `LeavePolicyService`, `TimeService`, and `ReportingDataService` to query from `Personnel` and `currentAssignment` (Personnel Domain) as the primary authoritative source.
  2. Implement safe fallback to legacy `users` fields and `department_org_unit_map` when domain assignments are not yet present.
  3. Keep `users.id` as the security identity without altering authorization boundaries or IDOR limits.
- **Consequences**:
  - Direct reliance on legacy columns is removed from core service business logic.
  - Full backward compatibility and zero regression maintained.
  - Ready for USER-04 (Frontend & API Layer Alignment).

## ADR-USER-04: Non-Breaking API & Frontend Alignment with Personnel Domain

- **Status**: Accepted
- **Date**: 2026-08-16
- **Context**: 
  Frontend and API clients require Personnel Domain data (UUID, Personnel Code, Status, Position, OrgUnit) without breaking existing consumers that expect legacy keys (`id`, `name`, `department_id`, `roles`, `profile`).
- **Decision**:
  1. Add additive accessors and appends (`personnel_uuid`, `personnel_code`, `personnel_status`, `position`) on the `User` model.
  2. Eager load `personnel`, `currentAssignment`, `orgUnit`, and `personnelType` in core API endpoints (`/api/login`, `/api/user`, `/api/admin/users`, `/api/users/list`).
  3. Extend Pinia `useAuthStore` with safe getter accessors that fallback automatically to legacy fields if domain attributes are absent.
  4. Update `UsersManagementView.vue` and `UserProfileView.vue` to display domain attributes.
- **Consequences**:
  - Zero breaking changes to API contracts or UI components.
  - Seamless consumption of Personnel Domain data across the entire application.
  - Ready for USER-05 (Deprecation & Verification).

## ADR-USER-05: Authorization Technical Debt Cleanup & Zero Leakage Verification

- **Status**: Accepted
- **Date**: 2026-08-16
- **Context**: 
  Scattered hardcoded role checks in frontend views (`MyLeavesView.vue`, `DashboardView.vue`) and legacy references in approval delegation queries created security debt and brittle role assumptions.
- **Decision**:
  1. Replace all hardcoded role names with permission-based checks and ABAC getters via `useAuthStore`.
  2. Eliminate remaining `role_id` column references in `ApprovalController.php` in favor of Spatie `roles` relation queries.
  3. Support permission-based approver querying in `LeaveRequestNotificationService`.
  4. Perform comprehensive non-regression verification across all core domains.
- **Consequences**:
  - Staff positions and organizational ranks are fully decoupled from system roles.
  - Complete elimination of authorization leakage and IDOR vulnerabilities.
  - User Modernization track (USER-00 through USER-05) is 100% complete and verified for PRODUCTION.

## ADR-APPROVAL-01: Dynamic Approver Resolvers & Workflow Configuration Hardening

- **Status**: Accepted
- **Date**: 2026-08-16
- **Context**: 
  Approval workflow steps were rigidly tied to static `role_id` entries, which failed to adapt to organizational unit structures (e.g. dynamic department heads) and required hardcoding role names.
- **Decision**:
  1. Introduce `ApproverResolverService` supporting 7 dynamic resolver types (`DEPARTMENT_HEAD`, `REQUESTER_SUPERVISOR`, `DIVISION_DIRECTOR`, `DIRECTOR`, `HR_OFFICER`, `ROLE`, `SPECIFIC_USER`).
  2. Implement dynamic resolution through `org_unit_managers`, `OrgScopeResolver`, and Spatie permissions.
  3. Store immutable snapshots in `LeaveApproval` so workflow modifications never mutate historical or pending requests.
  4. Enforce optimistic concurrency control (`X-Workflow-Fingerprint` returning HTTP 409).
- **Consequences**:
  - Full decoupling between organizational hierarchy and system roles.
  - Rock-solid workflow stability, concurrency safety, and audit traceability.






## ADR-APPROVAL-02: Snapshot ต้องเก็บ "วิธีหาตัวผู้อนุมัติ" และรายการงานต้องใช้เกณฑ์เดียวกับการอนุมัติ

- **Status**: Accepted
- **Date**: 2026-08-19
- **Context**:
  ADR-APPROVAL-01 กำหนดให้ `leave_approvals` เป็น snapshot ที่ไม่เปลี่ยนตามการแก้สายอนุมัติ
  แต่ snapshot จริงเก็บเฉพาะ `required_role_id` ขั้นตอนแบบ `DEPARTMENT_HEAD` / `DIRECTOR` /
  `SPECIFIC_USER` จึงถูกลดรูปเป็น `ROLE` ตั้งแต่วินาทีที่ยื่นใบลา ผู้อนุมัติตัวจริงกดอนุมัติแล้วได้ 403
  ขณะเดียวกันขั้นตอนที่ไม่มี role กำกับเลยกลับเปิดให้ผู้ใช้ที่ active ทุกคนอนุมัติได้
  และรายการ "รออนุมัติ" ยังคัดด้วยการจับคู่ role ตรง ๆ ซึ่งเป็นคนละเกณฑ์กับตอนกดอนุมัติ
- **Decision**:
  1. snapshot ต้องผนึกกติกา ณ วันยื่นให้ครบ — `approver_type`, `specific_user_id`, `permission_name`
     ไม่ใช่แค่ `required_role_id`
  2. การหาตัวผู้อนุมัติเป็น fail-closed — ไม่มีเกณฑ์คัด = ไม่มีใครมีสิทธิ์
  3. ห้ามเติม role ให้ขั้นตอนอัตโนมัติโดยที่ผู้ดูแลไม่ได้เลือก สิ่งที่หน้าจอแสดงต้องเป็นสิ่งที่ระบบใช้จริง
  4. รายการงานที่ต้องอนุมัติ (worklist) ต้องคัดด้วย `ApproverResolverService` ตัวเดียวกับที่ใช้ตัดสินสิทธิ์
     ตอนกดอนุมัติ — กติกาคือ "เห็น = อนุมัติได้"
  5. worklist ไม่ถูกหรี่ด้วยขอบเขตหน่วยงาน (`LeaveVisibility`) เพราะสิทธิ์อนุมัติแคบกว่าอยู่แล้ว
     และผู้อนุมัติข้ามสายงาน (ผอ. / ผู้อนุมัติเฉพาะราย) ต้องเห็นงานของตน
  6. สายอนุมัติคำขอยกเลิกใบลาใช้ snapshot + resolver ชุดเดียวกับการอนุมัติใบลา
- **Consequences**:
  - ผู้อนุมัติที่ระบุไว้ในสายอนุมัติทำงานได้จริงทุกรูปแบบ ไม่ต้องพึ่ง Super Admin กดแทน
  - ปิดช่องโหว่ที่ผู้ใช้ทั่วไปอนุมัติใบลาแทนผู้บริหารได้
  - ยังคงเหลือหนี้เชิงสถาปัตยกรรมที่ต้องตัดสินใจแยก: workflow engine (shadow mode) กับ
    `approval_steps`/`leave_approvals` ยังทำงานคู่ขนานกันอยู่
