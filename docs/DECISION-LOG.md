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

## ADR-USER-GOV-01: User accounts are never hard-deleted

- **Status**: Accepted
- **Date**: 2026-10-14
- **Context**:
  `users` is referenced by 60+ foreign keys. Several cascade (leaves, leave_balances, attendances, personnel, user_profiles, personal_data_access_logs), others restrict (personnel_assignments, ot_time_records). Deleting an account either destroyed historical records or failed with 500. PROJECT_RULES forbids deleting historical/effective-dated records and PROD phase forbids schema changes, so soft deletes (`deleted_at`) are not an option now.
- **Decision**:
  1. `DELETE /api/admin/users/{user}` always responds 409 and changes nothing; the route and `delete_users` permission stay so callers get a clear message.
  2. Personnel who leave the organisation are suspended (`is_active = false`), which revokes all tokens and blocks login.
  3. Erasure requests under PDPA, if ever required, need a separate anonymisation procedure approved by the data controller.
- **Consequences**:
  - Leave, attendance and audit history stay intact for every former employee.
  - Mistakenly created accounts cannot be removed; they are suspended instead.

## ADR-LEAVE-APPROVAL-02: อนุมัติใบลาของตนเองได้รายขั้น และปิดช่องข้ามสายอนุมัติ

- **Status**: Accepted (เจ้าของระบบตัดสิน)
- **Date**: 2026-10-14
- **Context**:
  เดิมห้ามอนุมัติใบลาของตนเองทุกขั้นแบบตายตัว สายอนุมัติคือ งานบุคลากรตรวจสอบ → รองผู้อำนวยการ → ผู้อำนวยการ
  ใบลาของผู้อำนวยการจึงค้างที่ขั้นสุดท้าย (ไม่มีผู้อนุมัติคนอื่นในขั้นนั้น) ระหว่างตรวจยังพบช่องข้ามสายอนุมัติ 2 จุด:
  ผู้รับมอบอำนาจจากผู้อำนวยการอนุมัติใบลาของผู้อำนวยการในนามผู้อำนวยการได้ และ `POST /admin/leaves`
  (บันทึกวันลาแทน ซึ่งอนุมัติครบทุกขั้นทันที) บันทึกให้ตนเองได้
- **Decision**:
  1. เพิ่ม `allow_self_approval` รายขั้นใน `approval_steps` (ตั้งที่หน้าจัดการลำดับการอนุมัติ) และ pin ลง `leave_approvals` ตอนยื่น
  2. ตามมติ ทุกขั้นเปิดสวิตช์: ผู้อำนวยการอนุมัติขั้นสุดท้ายของใบลาตนเอง (ใบลาผ่านงานบุคลากร → รองฯ ก่อนตามปกติ)
     รองผู้อำนวยการอนุมัติขั้นที่ 2 ของใบลาตนเอง แล้วผู้อำนวยการอนุมัติขั้นสุดท้าย · งานบุคลากรตรวจสอบขั้นที่ 1 ของใบลาตนเองได้
  3. อนุมัติของตนเองได้เฉพาะเมื่อเป็นผู้อนุมัติของขั้นนั้น **โดยตรง** — สิทธิ์ `leave.management.manage`, Super Admin
     และการรับมอบอำนาจใช้กับใบลาของตนเองไม่ได้ (ตัดสินที่ `ApproverResolverService::canSelfApprove()` ที่เดียว)
  4. ผู้รับมอบอำนาจอนุมัติใบลาของผู้มอบในนามผู้มอบไม่ได้
  5. ห้ามบันทึกวันลาแทนให้ตนเอง · ห้ามปฏิเสธใบลาของตนเอง (ใช้การยกเลิกแทน)
  6. บันทึก `leave_approvals.is_self_approval` และ `self_approved` ใน audit แสดงป้าย "อนุมัติด้วยตนเอง" บนไทม์ไลน์
- **Consequences**:
  - ใบลาของผู้อำนวยการไม่ค้างอีก และทุกครั้งที่อนุมัติของตนเองตรวจย้อนได้
  - ปิดสวิตช์รายขั้นได้โดยไม่แก้โค้ด ใบที่ยื่นแล้วใช้ค่าที่ pin ไว้


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

## ADR-APPROVAL-04: คำขอยกเลิกใบลาเป็นสายอนุมัติของตัวเอง บนขั้นตอนชุดเดียวกับการขอลา

- **Status**: Accepted
- **Date**: 2026-08-19
- **Context**:
  ระเบียบราชการถือว่าการยกเลิกวันลาที่อนุมัติแล้วเป็นการแก้ไขเอกสารที่มีผลผูกพัน
  จึงต้องผ่านการพิจารณาเหมือนตอนขอลา แต่ระบบเดิมให้คำขอยกเลิก "ยืม" สายของใบลาไปใช้:
  เขียนทับ `leaves.approval_level`, ไม่มี snapshot ของตัวเอง, ไม่บันทึกว่าใครอนุมัติขั้นไหน
  และเดินตาม `approval_steps` ปัจจุบันจึงเปลี่ยนตามการแก้สายอนุมัติ
- **Decision**:
  1. คำขอยกเลิกใช้ **ขั้นตอนชุดเดียวกับการขอลา** (ผู้ดูแลตั้งค่าที่เดียว ไม่ต้องดูแลสองชุด)
  2. แต่ผนึก **snapshot ของตัวเอง** ณ วันที่ขอยกเลิก แยกด้วย `leave_approvals.stage`
  3. เดินระดับด้วย `leaves.cancellation_approval_level` — ห้ามแตะ `approval_level` ของใบลาเดิม
     เพราะเป็นประวัติว่าใบลาผ่านอนุมัติมาถึงขั้นใด
  4. ทุกขั้นบันทึกผู้อนุมัติ ตำแหน่ง ลายเซ็น ความเห็น เวลา และผู้มอบอำนาจ เท่ากับสายขอลา
  5. ยื่นคำขอยกเลิกใหม่ได้เมื่อคำขอเดิมถูกปฏิเสธ และต้องเริ่มสายใหม่ตั้งแต่ขั้นแรก
  6. ความสัมพันธ์ `Leave::approvals()` กรองเฉพาะ stage `request` เพื่อให้ผู้เรียกเดิมทั้งหมด
     (เอกสาร PDF, timeline, การตรวจสิทธิ์) ได้ผลเท่าเดิม และเพิ่ม `cancellationApprovals()`
     กับ `allApprovals()` สำหรับผู้เรียกที่ต้องการอีกสองมุม
- **Consequences**:
  - คำขอยกเลิกตรวจสอบย้อนหลังได้เต็มรูปแบบ และไม่ถูกกระทบเมื่อสายอนุมัติเปลี่ยน
  - แก้บั๊กที่อนุมัติยกเลิกแล้ววันลาไม่ถูกคืนเข้าสิทธิ์ (คืนก่อนเปลี่ยนสถานะเป็น Cancelled)
  - ถ้าภายหลังต้องการให้สายยกเลิกสั้นกว่าสายขอลา ต้องเพิ่มการตั้งค่าสายที่สอง ซึ่งยังไม่ทำในรอบนี้

## ADR-APPROVAL-05: สายอนุมัติคำขอยกเลิกตั้งค่าแยกได้ โดยค่าเริ่มต้นสืบทอดจากสายใบลา

- **Status**: Accepted
- **Date**: 2026-08-19
- **Context**:
  ADR-APPROVAL-04 กำหนดให้คำขอยกเลิกใช้ขั้นตอนชุดเดียวกับการขอลาเสมอ ซึ่งตรงกับ
  การปฏิบัติของหน่วยงานส่วนใหญ่ แต่บางแห่งต้องการให้การยกเลิกสั้นกว่า เช่นเสนอ
  ผู้อำนวยการโดยตรงโดยไม่ต้องผ่านหัวหน้าแผนกซ้ำ
- **Decision**:
  1. แยกสายด้วยคอลัมน์ `approval_steps.chain` (`leave` / `cancellation`) — ตารางเดียว
     ไม่แตกตารางใหม่ เพราะโครงสร้างขั้นตอนและ resolver เหมือนกันทุกประการ
  2. **สายยกเลิกที่ไม่มีขั้นตอนเลย = ใช้สายของใบลา** (`ApprovalStep::stepsFor()`)
     เป็นค่าเริ่มต้นของระบบ การอัปเกรดจึงไม่เปลี่ยนพฤติกรรมของหน่วยงานที่ใช้อยู่
     และผู้ดูแลที่ไม่ต้องการแยกสายไม่ต้องตั้งค่าอะไรเพิ่ม
  3. สายของใบลาต้องมีอย่างน้อยหนึ่งขั้นเสมอ (ไม่มีขั้นตอน = ไม่มีใครอนุมัติใบลาได้)
     ส่วนสายยกเลิกบันทึกว่างได้ ซึ่งแปลว่ากลับไปสืบทอดสายของใบลา
  4. การบันทึก การตรวจ concurrency (fingerprint) และการดูตัวอย่างผู้อนุมัติ แยกตามสาย
  5. workflow engine (shadow, ADR-009) ยังสะท้อนเฉพาะสายของใบลา — สายยกเลิกไม่ออกเวอร์ชัน
     จนกว่าจะมีการตัดสินใจเรื่อง engine ในเฟสถัดไป
  6. ผู้เรียก `approval_steps` เดิมทุกจุดถูกระบุสายอย่างชัดแจ้ง ไม่ใช้ global scope
     เพื่อให้อ่านโค้ดแล้วเห็นทันทีว่ากำลังพูดถึงสายไหน (โดเมนนี้ตรวจสอบย้อนหลังได้สำคัญกว่าความสั้น)
- **Consequences**:
  - หน่วยงานเลือกได้ว่าจะให้การยกเลิกใช้สายเดียวกับใบลาหรือสายเฉพาะของตน
  - snapshot ของคำขอยกเลิกยังผนึก ณ วันที่ขอเหมือนเดิม การเปลี่ยนสายไม่ย้อนกระทบคำขอที่ค้างอยู่

## ADR-LEAVE-FORM-01 — แบบฟอร์มใบลาเป็นข้อมูลที่ตั้งค่าได้ ไม่ใช่โค้ด (2026-08-19)

- **Context**:
  แบบฟอร์มราชการแต่ละประเภทมีช่องว่างไม่เหมือนกัน และแต่ละสถานศึกษาใช้ถ้อยคำ/ชื่อหน่วยงาน
  ต่างกัน ระบบเดิมเลือกแบบฟอร์มด้วยการเทียบชื่อไทยของประเภทการลาในโค้ด
  (`$type->name === 'ลาพักผ่อน'`) ชื่อสถานศึกษาเขียนตายในไฟล์ blade และหน้าแก้เทมเพลต
  โฆษณาตัวแปรอย่าง `{{ $school_name }}` ที่ไม่เคยถูกส่งค่าให้จริง
- **Decision**:
  1. **การผูกแบบฟอร์ม** ย้ายไปเป็นข้อมูล: `leave_types.document_template_code`
     ตั้งค่าได้จากหน้าประเภทการลา — ถอยไปเดาจากชื่อไทยเฉพาะข้อมูลเก่าที่ยังไม่ได้ตั้งค่า
  2. **ชุดตัวแปรเดียว** (`App\Services\LeaveDocumentFields`) เป็นสัญญาระหว่างข้อมูลในระบบ
     กับช่องว่างในแบบฟอร์ม ทุกแบบฟอร์ม (blade และเทมเพลตในฐานข้อมูล) ใช้ชุดเดียวกัน
     และมี `catalog()` เป็นเอกสารในตัวสำหรับหน้าผู้ดูแล
  3. **ช่องเฉพาะแบบฟอร์ม** (ชื่อวัด ชื่อภริยา ฯลฯ) นิยามเป็นข้อมูลใน `leave_types.extra_fields`
     ค่าที่กรอกเก็บใน `leaves.meta_data.extra_fields` — เพิ่มแบบฟอร์มใหม่ไม่ต้องเพิ่มคอลัมน์
  4. ชื่อสถานศึกษา/สังกัด/ตำแหน่งผู้บริหารมาจากตาราง `settings` เท่านั้น ไม่มีค่า default
     เป็นชื่อสถานศึกษาใดในโค้ด (เว้นว่างไว้ให้เป็นเส้นประของแบบฟอร์ม)
  5. การพิมพ์ยังยึด LEGACY-07: ใบลาที่ pin เวอร์ชันไว้พิมพ์ด้วยเวอร์ชันเดิมเสมอ การแก้เทมเพลต
     หรือ "คืนค่าแบบฟอร์มตั้งต้น" จึงไม่ย้อนเปลี่ยนเอกสารที่ออกไปแล้ว
- **Consequences**:
  - ผู้ดูแลเพิ่มประเภทการลา/เปลี่ยนชื่อ/สลับแบบฟอร์ม/เพิ่มช่องกรอก ได้เองโดยไม่ต้องแก้โค้ด
  - แบบฟอร์มในไฟล์ blade กลายเป็นเพียง "ค่าตั้งต้น" ที่ถูกคัดลอกลงฐานข้อมูล
  - ราคาที่จ่าย: แบบฟอร์มแต่ละไฟล์มี CSS ซ้ำกัน เพราะต้อง self-contained ให้แก้ไขได้ทั้งไฟล์จากหน้าเว็บ

## ADR-DOC-ENGINE-01: Universal Document & Report Engine บน Compatibility Layer

- **Status**: Accepted
- **Date**: 2026-08-20
- **Context**:
  ระบบเดิมออกเอกสารด้วย dompdf จากแม่แบบ HTML ซึ่งจัดตำแหน่งให้ตรงแบบฟอร์มราชการได้ยาก
  ผู้ดูแลเพิ่มแบบฟอร์มใหม่เองไม่ได้ ไม่มีการเก็บไฟล์เอกสารที่ออกไปแล้ว และรายงานถูกเขียนตายในโค้ด
  รายละเอียดทั้งหมดอยู่ใน `docs/checkpoints/DOC-ENGINE-00-ASSESSMENT.md`
- **Decision**:
  1. Engine ใหม่ทำงานคู่ขนานกับของเดิม ผ่าน `LeaveDocumentGateway` เป็น Compatibility Layer
     ปิดธง = เส้นทางเดิมทั้งหมด และ engine ล้มเมื่อใดก็ถอยไปเส้นทางเดิมเสมอ (ผู้ใช้ต้องพิมพ์เอกสารได้)
  2. `render_mode` แยกแม่แบบ HTML เดิม (`html`) ออกจากแม่แบบวางฟิลด์บน PDF (`pdf_overlay`)
     แม่แบบเดิมทั้ง 10 ตัวไม่ถูกแตะและยังใช้งานได้ตลอดไป
  3. เพิ่ม `status` (DRAFT/ACTIVE/RETIRED/ARCHIVED) ควบคู่กับ `is_active` เดิม ไม่แทนที่
     เวอร์ชันที่มีเอกสารอ้างอิงห้ามลบและห้ามแก้ในที่เดิม ต้อง Duplicate → Modify → Publish
  4. เอกสารเข้าถึงข้อมูลผ่าน Data Provider ที่ลงทะเบียนในโค้ดเท่านั้น ตารางแหล่งข้อมูลเป็นแค่รายการให้เลือก
     ไม่มีจุดใดรับ SQL จากหน้าจอ และขอบเขตการมองเห็นยังเป็นของ `ReportingScopeService` เดิม
  5. เอกสารฉบับสมบูรณ์เป็น IMMUTABLE บังคับที่ตัวโมเดล การออกซ้ำ = revision ใหม่ ของเดิมเป็น SUPERSEDED ไม่ถูกลบ
  6. เอกสารย้อนหลังอ่านจากไฟล์ที่เก็บไว้ก่อนเสมอ ไม่เรนเดอร์ใหม่จากข้อมูลบุคคล/ผู้บริหาร/แบบฟอร์มปัจจุบัน
  7. ค่าชดเชยเครื่องพิมพ์แยกจาก field mapping (global → template → user) ห้ามขยับ mapping เพื่อแก้ปัญหาเครื่องพิมพ์
  8. ฟอนต์ไทยฝังในระบบ (`storage/fonts/fpdf` สร้างด้วย `php artisan document:install-fonts`) ไม่พึ่งฟอนต์ของ host
  9. สิทธิ์ `document.*` ชุดใหม่ ใช้ควบคู่กับสิทธิ์ตั้งค่าระบบเดิมในระยะเปลี่ยนผ่าน เพื่อไม่ตัดสิทธิ์ผู้ดูแลที่ใช้งานอยู่
- **Consequences**:
  - เพิ่มแบบฟอร์ม/เวอร์ชัน/รายงานใหม่ได้จากหน้าผู้ดูแลโดยไม่ต้องแก้โค้ด
  - Engine ไม่ผูกกับ E-Leave — โมดูลอื่นใช้ได้ด้วยการเพิ่ม provider + binding rule
  - ยังต้องทำต่อ: ย้าย leave type ที่เหลือเข้า engine (DOC-ENGINE-05) และหน้าออกแบบรายงานแบบลากคอลัมน์ (DOC-ENGINE-07)

## ADR-TPL-SEC-01: HTML leave forms are code-owned; admins design forms only through the Document Engine

- **Status**: Accepted
- **Date**: 2026-10-15
- **Context**:
  The legacy template page let admins edit `document_templates.content_html`, which was rendered with `Blade::render()` both when printing a leave and in a preview endpoint that accepted raw HTML. Blade compiles to PHP, so this gave every holder of the settings permission arbitrary code execution on the server. A safe sandboxed HTML engine would require rewriting all forms and HR staff would still have to edit HTML. The Document Engine (ADR-DOC-ENGINE-01) already offers safe, structured design: whitelisted data providers and field coordinates on the official PDF.
- **Decision**:
  1. HTML forms render only from `resources/views/pdf/*.blade.php` (reviewed code). `content_html` is kept as data but never executed.
  2. Remove HTML template create/update/delete and the HTML preview endpoint; preview renders the code view with sample data.
  3. Admin-side form customization happens in the Document Center (upload official PDF, place fields, publish), per leave type.
  4. Pinned `document_template_version_id` still records which form a leave was issued with and sets paper size; immutability of issued documents comes from the Document Engine's finalized files.
- **Consequences**:
  - Closes the code-execution path. No schema change.
  - Changing an HTML form layout now requires a code change and review.
  - An HTML form change applies to reprints of older leaves that are not served from a finalized Document Engine file.

## ADR-DELEGATION-01: Acting authority comes from an HR-approved register; admins only record decisions on behalf of the real authority

- **Status**: Accepted
- **Date**: 2026-10-16
- **Context**:
  Thai government documents signed by someone acting for an absent authority must say so under the signature ("รักษาราชการแทน …" when the post is vacant or the holder cannot act, "ปฏิบัติราชการแทน …" under a delegation order). The system had a `user_delegations` table any user could fill for themselves with immediate effect, printed nothing about the capacity, and let anyone with `leave.management.manage` approve any step in their own name — so forms showed an HR officer as the person who granted the leave.
  Owner decisions 2026-10-16: support both acting types; HR keeps the register (users may request, HR approves); an admin may only record a decision on behalf of the real authority; migration allowed.
- **Decision**:
  1. `user_delegations` becomes the register: type, acting position, order reference, status (`pending` / `approved` / `revoked`) with who requested/approved/revoked. Only approved, active rows within their dates give authority. Rows are revoked, never deleted.
  2. `LeaveApprovalSigner` decides the capacity of every leave and cancellation decision: *self* (LEAVE-APPROVAL-02), *direct*, *delegate* (from the register) or *record*. Anything else is 403.
  3. *Record* requires the admin to name the signer (`on_behalf_of`), who must be the step's authority or their in-effect delegate and not the requester. The approval is stored in the signer's name with no image signature and `recorded_by_user_id`.
  4. The capacity is snapshotted on `leave_approvals` (`acting_type`, `acting_position`, `acting_order_ref`) so reprints never change when the register changes; forms print it as an extra line under the signer.
- **Consequences**:
  - Admins can no longer push a leave through every step alone; bulk approval skips leaves they would have to record.
  - Leaves HR records directly (`/admin/leaves`) follow the same rule (owner decision 2026-10-16): HR must pick the real signer of every step; the officer is kept only as `recorded_by_user_id`.
  - `leave.management.manage` is granted to the HR Head role (owner decision 2026-10-16), which also opens the other HR leave functions guarded by it.
  - The workflow engine's manager bypass is removed; otherwise the grant would let HR Head decide any OT order or attendance correction (including their own).
- Delegation requests no longer take effect immediately — HR must approve them.

## ADR-DOC-DESIGNER-01: Leave forms are designed per leave type on generated, organisation-neutral backgrounds

- **Status**: Accepted
- **Date**: 2026-10-15
- **Context**:
  The owner wants every leave type's form designed in the Document Engine's Visual Designer. The five PDFs in `resources/document-templates` are another college's Word forms with its name baked in and no usable text layer, field positions were placeholders, four leave types had no PDF at all, enabling the engine required editing `.env`, leaves never pinned PDF versions, and ticked checkboxes crashed the renderer.
- **Decision**:
  1. Generate a blank background for each of the 9 system forms from the code-owned HTML forms; organisation, director and all data are overlay fields filled from settings and the leave.
  2. Place fields automatically by rendering the form with marker values (and one render per checkbox/signature) and reading the boxes from dompdf's layout — the background is rendered with invisible values so both layouts are identical.
  3. One field catalogue (`LeaveFormFieldMap`) feeds both the designer and the engine's values, from the same data as the HTML forms.
  4. Per-leave-type activation = an active binding of the type to its `designer_<code>` template with a published version, switched from the admin page. `DOCUMENT_ENGINE_ENABLED` (default true) remains the global kill switch.
  5. New leaves pin the enabled PDF version at submission; earlier leaves keep their pinned form.
- **Consequences**:
  - Admins can still upload their own official PDF in the designer and re-place fields.
  - Generation re-renders a form ~20–30 times (~15–30 s per form).
  - Leave types without a system form (military call-up, international organisation, vocational rehabilitation) still need a PDF uploaded by the admin.

