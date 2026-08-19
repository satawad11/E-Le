# CHANGELOG

All notable changes to the E-la project will be documented in this file.

## [Unreleased]

### [APPROVAL-02] - 2026-08-19
#### Fixed
- ผู้อนุมัติตัวจริงถูกปฏิเสธสิทธิ์ในขั้นตอนของตนเอง (403): snapshot ใน `leave_approvals` ไม่เคยเก็บ `approver_type` ทำให้ทุกขั้นตอนถูกลดรูปเป็นแบบ `ROLE` ตอนยื่นใบลา
- ช่องโหว่ fail-open ใน `ApproverResolverService`: ขั้นตอนที่ไม่มี role/permission กำกับ (เช่นแบบ `SPECIFIC_USER`) เคยคืนผู้ใช้ที่ active ทั้งหมดเป็นผู้มีสิทธิ์อนุมัติ ปัจจุบันคืนรายการว่าง (fail-closed)
- `Admin\WorkflowController` เคยเติม `role_id` (Super Admin/Director/HR Head) ให้ขั้นตอนอัตโนมัติแบบเงียบ ๆ ทำให้สิทธิ์อนุมัติรั่วและหน้าจอไม่ตรงกับที่ระบบใช้จริง
- รายการ "รออนุมัติ" กับการกดอนุมัติใช้คนละตรรกะ ทำให้หัวหน้าแผนกต้นสังกัดไม่เห็นใบลาที่ต้องอนุมัติ
- สายอนุมัติคำขอยกเลิกใบลาเทียบ `role_id` ตรง ๆ ไม่รองรับ resolver/การมอบอำนาจ และไม่ตรวจสถานะคำขอยกเลิกหรือห้ามอนุมัติของตนเอง

#### Added
- Migration `2026_08_19_090000_add_resolver_snapshot_to_leave_approvals.php` เพิ่ม `approver_type`, `specific_user_id`, `permission_name` ใน `leave_approvals` พร้อม backfill ใบลาที่ยังรออนุมัติ
- Automated test suite `Tests\Feature\LeaveApprovalChainSnapshotTest` (end-to-end ตั้งค่า → ยื่น → เห็นในรายการ → อนุมัติ)

#### Changed
- `ApprovalController` เหลือเฉพาะรายการรออนุมัติ และคัดด้วย `ApproverResolverService` ตัวเดียวกับตอนอนุมัติจริง
- `LeaveService::createLeaveRequest` และ `GovernmentApprovalController::step()` คัดลอกวิธีหาตัวผู้อนุมัติลง snapshot ครบทุกฟิลด์
- `LeaveCancellationController` ใช้ snapshot + resolver ชุดเดียวกับการอนุมัติใบลา

#### Removed
- `ApprovalController::approve()` / `reject()` ที่ไม่มี route ชี้ถึงตั้งแต่ย้ายไป `routes/leave_government.php` (รวมตรรกะ auto-approve ของ Super Admin ที่ไม่ถูกเรียกใช้แล้ว)

### [APPROVAL-04] - 2026-08-19
#### Added
- สายอนุมัติของ "คำขอยกเลิกใบลา" แบบเดียวกับการขอลา — migration `2026_08_19_140000_add_cancellation_approval_chain.php` เพิ่ม `leave_approvals.stage` (`request` / `cancellation`) และ `leaves.cancellation_approval_level` พร้อม backfill คำขอยกเลิกที่ค้างอยู่
- บันทึกรายขั้นของคำขอยกเลิก: ผู้อนุมัติ ตำแหน่ง ลายเซ็น ความเห็น เวลา และผู้มอบอำนาจ เช่นเดียวกับการอนุมัติใบลา (`LeaveAuditLog` action `cancellation_approved` / `cancellation_rejected`)
- แสดงลำดับการอนุมัติยกเลิกในหน้า "อนุมัติยกเลิกใบลา" และเปิดให้ลงลายเซ็นตอนอนุมัติคำขอยกเลิก
- Automated test suite `Tests\Feature\LeaveCancellationChainTest` (9 tests, 86 assertions)

#### Fixed
- อนุมัติคำขอยกเลิกใบลาที่อนุมัติแล้ว วันลาไม่ถูกคืนเข้าสิทธิ์ — เดิมเปลี่ยนสถานะใบลาเป็น `Cancelled` ก่อนเรียก `restoreBalance()` ซึ่งคืนโควตาเฉพาะใบที่ยังเป็น `Approved`
- คำขอยกเลิกเคยเขียนทับ `leaves.approval_level` ของใบลาเดิม ทำให้ประวัติว่าใบลาผ่านอนุมัติถึงขั้นใดหายไป
- คำขอยกเลิกที่ค้างอยู่เคยเปลี่ยนตามเมื่อผู้ดูแลแก้สายอนุมัติ ปัจจุบันยึด snapshot ณ วันที่ขอยกเลิก
- รายการรออนุมัติเคยตัดสินสิทธิ์ของคำขอยกเลิกด้วยสายอนุมัติของการขอลา (ซึ่งอนุมัติจบไปแล้ว) จึงตอบผิดคน

### [APPROVAL-03] - 2026-08-19
#### Added
- `POST /api/admin/workflow-steps/preview` (สิทธิ์ `manage_workflow_steps`) ทดลองว่าใครคือผู้อนุมัติจริงของแต่ละขั้นสำหรับผู้ยื่นที่เลือก ใช้ `ApproverResolverService` ตัวเดียวกับตอนอนุมัติจริง และรองรับการทดลองสายอนุมัติที่ยังไม่บันทึก
- ปุ่ม "ดูตัวอย่างผู้อนุมัติจริง" พร้อมหน้าต่างแสดงรายชื่อผู้อนุมัติรายขั้นใน `WorkflowManagementView.vue` แจ้งเตือนเมื่อขั้นตอนใดไม่มีผู้อนุมัติ หรือได้ผู้ยื่นเป็นผู้อนุมัติของตัวเอง

#### Fixed
- หน้า "อนุมัติใบลา" ค้างที่ skeleton เมื่อมีใบลารออนุมัติ — `ApprovalsView.vue` เรียก `ThaiDateFormatter` โดยไม่ได้ import ทำให้ render ล้มทั้งหน้า (`Cannot read properties of undefined (reading 'toThaiSlash')`)

### [APPROVAL-01] - 2026-08-16
#### Added
- Migration `2026_08_16_020000_add_resolver_fields_to_approval_steps.php` adding `approver_type`, `specific_user_id`, `permission_name` to `approval_steps`.
- Service `App\Services\Workflow\ApproverResolverService` supporting dynamic approver resolution across 7 resolver types (`DEPARTMENT_HEAD`, `REQUESTER_SUPERVISOR`, `DIVISION_DIRECTOR`, `DIRECTOR`, `HR_OFFICER`, `ROLE`, `SPECIFIC_USER`).
- Automated test suite `Tests\Feature\ApprovalResolverAndWorkflowHardeningTest`.
- Checkpoint report `docs/checkpoints/APPROVAL-01-REPORT.md`.

#### Changed
- `App\Http\Controllers\Admin\WorkflowController` updated with resolver validation, specific user selection, and hardened fingerprint hashing.
- `App\Http\Controllers\ApprovalController` and `App\Http\Controllers\GovernmentApprovalController` refactored to verify authorization through `ApproverResolverService`.
- `App\Services\Workflow\LeaveWorkflowProvisioner` step mapping updated to reflect dynamic assignee types.
- `WorkflowManagementView.vue` enhanced with resolver type selection, specific user picker, and mobile responsiveness.

### [USER-05] - 2026-08-16
#### Changed
- Removed legacy hardcoded role names in `MyLeavesView.vue`, `DashboardView.vue`, and `UserProfileView.vue` in favor of permission-based getters.
- Cleaned legacy `role_id` queries in `ApprovalController.php` delegation checks.
- Enhanced `LeaveRequestNotificationService` to query approvers by permissions as well as roles.

#### Added
- Automated test suite `Tests\Feature\AuthorizationCleanupAndVerificationTest`.
- Checkpoint report `docs/checkpoints/USER-05-REPORT.md`.

### [USER-04] - 2026-08-16
#### Added
- Model appends and accessors in `App\Models\User`: `personnel_uuid`, `personnel_code`, `personnel_status`, `position`.
- Enhanced payloads in `AuthController` (`/api/login`, `/api/user`) and `Admin\UserController` (`/api/admin/users`, `/api/users/list`) including Personnel Domain data with full backward compatibility.
- Personnel Domain helper getters in Pinia auth store (`useAuthStore`): `personnel`, `currentAssignment`, `personnelCode`, `displayName`, `positionName`, `orgUnitName`.
- Automated test suite `Tests\Feature\PersonnelApiAlignmentTest`.
- Checkpoint report `docs/checkpoints/USER-04-REPORT.md`.

#### Changed
- `UsersManagementView.vue` and `UserProfileView.vue` updated to display staff information from Personnel Domain with fallback to legacy fields.

### [USER-03] - 2026-08-16
#### Changed
- Refactored `App\Services\OrgScopeResolver::homeOrgUnitId` to read org unit from active `PersonnelAssignment` in Personnel Domain before fallback.
- Refactored `App\Services\LeavePolicyService::resolve` to read `personnel_type_id` from Personnel Domain assignment and verify service eligibility with `personnel.effective_from`.
- Refactored `App\Services\Time\TimeService::isWorkingDay` to resolve department/org unit from Personnel Domain.
- Refactored `App\Services\Reporting\ReportingDataService::personnel` to read formatted name, position, organization, and personnel type from Personnel Domain.
- Updated `App\Services\LeaveService::createLeaveRequest` to eager load `personnel` and `currentAssignment`.

#### Added
- Automated test suite `Tests\Feature\PersonnelDomainCoreServicesTest`.
- Checkpoint report `docs/checkpoints/USER-03-REPORT.md`.

### [USER-02] - 2026-08-16
#### Added
- `App\Services\PersonnelSyncService` with recursion guard (`withoutSyncRecursion`), multi-table database transaction wrapping, and effective-dating position manager (`assignPosition`).
- Model Observers: `UserObserver`, `PersonnelObserver`, `UserProfileObserver`, and `PersonnelAssignmentObserver`.
- Observers registered in `AppServiceProvider::boot()`.
- Method `User::currentAssignment()` and `Personnel::currentAssignment()`.
- Automated test suite `Tests\Feature\PersonnelSyncTest`.
- Checkpoint report `docs/checkpoints/USER-02-REPORT.md`.

#### Changed
- Delegated inline model lifecycle hooks in `App\Models\User` to `UserObserver` for unified synchronization.

### [USER-01] - 2026-08-16
#### Added
- Migration `2026_08_16_010000_create_personnel_foundation.php` creating `personnel` table with UUID, unique `user_id` 1:1 foreign key, `personnel_code`, `title`, `first_name`, `last_name`, `status`, `effective_from`, `effective_to`, and composite effective date index.
- Idempotent and transaction-safe data backfill in migration populating `personnel` from `users` and `user_profiles`.
- Model `App\Models\Personnel` using `HasPublicUuid` and defining `user()`, `assignments()`, and `full_name` accessor.
- Relationship `User::personnel()` (1:1 hasOne) in `App\Models\User`.
- Helper relationships `user()`, `orgUnit()`, `personnelType()`, and `personnel()` in `App\Models\PersonnelAssignment`.
- Automated test suite `Tests\Feature\PersonnelEntityFoundationTest`.
- Checkpoint report `docs/checkpoints/USER-01-REPORT.md`.

#### Changed
- Refactored legacy `whereIn('role_id', ...)` in `App\Http\Controllers\ApprovalController` to use Spatie roles relation (`whereHas('roles', ...)`).

### [USER-00] - 2026-08-16
#### Added
- Comprehensive impact analysis report `docs/checkpoints/USER-00-IMPACT-ANALYSIS.md` cataloging 68 foreign keys, authentication, session, token, and authorization dependencies.
