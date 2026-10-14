# CHANGELOG

All notable changes to the E-la project will be documented in this file.

## [Unreleased]

### [AUDIT-PDPA-01] - 2026-10-14 — Mask personal data in user audit log
#### Security
- `user.created` / `user.updated` / `user.deleted` audit entries stored `users.phone` (decrypted by its `encrypted` cast), `telegram_chat_id` and `line_user_id` in plaintext in `audit_logs.before/after`. These fields (`User::AUDIT_REDACTED_ATTRIBUTES`) are now written as `[REDACTED]` (null stays null), and `user.updated` lists which of them changed in `after.redacted_changed`. `password` / `remember_token` remain excluded.
- Existing `audit_logs` rows are not modified (append-only); rows written before this change may still contain plaintext values.
- Test `CentralAuditLogTest::test_user_audit_never_stores_phone_or_messaging_ids_in_plaintext`.

### [USER-IMPORT-01] - 2026-10-14 — Restore user CSV import
#### Fixed
- `POST /api/admin/users/import` returned 500 on every call: the route and the "นำเข้า CSV" button existed but `UserController::importCsv()` was lost in the LEGACY-00 baseline snapshot (`6139212`). Restored from the unmerged commit `5b1497c`, with these fixes:
  - `phone` was written to `user_profiles` and silently dropped; it now goes to `users.phone` (encrypted).
  - Duplicate national ID numbers (against existing profiles or earlier rows in the same file) are rejected, because ID-card login looks up `id_card_number_hash` and takes the first match. IDs must be 13 digits.
  - Empty CSV cells are treated as "not provided" instead of empty strings (e.g. `personnel_type_id`).
#### Changed
- Import rules: passwords are never read from the file (a random unknown password is set; the user resets it through "ลืมรหัสผ่าน"). Every imported user gets a `personnel_type_id` (default `civil_servant`) and current-fiscal-year leave balances from policy. Bad rows are skipped and reported by line number. One `user.imported` audit entry per import records the counts and created user IDs.
- Leave balance provisioning moved into `UserController::provisionLeaveBalances()`, which `store()` and `importCsv()` now share.
- The CSV template in `UsersManagementView.vue` includes an optional `personnel_type_id` column.
#### Added
- Tests `Tests\Feature\UserImportTest` (10 tests).

### [LEAVE-FIX-01] - 2026-10-13 — Leave lifecycle audit & fix
#### Fixed
- Cancelling an approved leave never returned the used days (status was set to `Cancelled` before `restoreBalance()` ran).
- `approve-cancellation` / `reject-cancellation` accepted leaves with no pending cancellation request — approvers could advance `approval_level` of a pending leave and skip their own approval step. Now requires `Approved` + `cancellation_status = Pending`, forbids self-decision, and authorizes through `ApproverResolverService`.
- HR-recorded leave (`POST /admin/leaves`) left its days in `reserved` forever and every approval step `Pending`; past dates were blocked. Now finalized via `LeaveService::finalizeRecordedLeave()` and timing rules are skipped for HR backfill.
- Half-day leave on a weekend/holiday counted 0.5 day; morning + afternoon on the same day were treated as overlapping.
- Editing a leave (`PUT /leaves/{id}`) changed dates without recalculating `total_days` / reservation. Pending leaves are now rescheduled through `LeaveService::reschedule()`; approved leaves must use cancellation.
- `leave_approvals` snapshot did not pin `approver_type` / `specific_user_id` / `permission_name`, so resolver-based steps (department head, director, specific user) could not be approved; the pending list used `approval_steps.role_id` and never showed them.
- Leave balances of a new fiscal year did not exist until the first request; balances endpoint now initializes them from policy. Seeder used calendar year and hard-coded quotas.
- Carry-forward skipped users with no `personnel_type_id`, and ignored the 10-year rule (accumulate 30 instead of 20).
- Reports defaulted to the calendar year while filtering by fiscal year (showed the closed fiscal year from Oct–Dec). Dashboard months now follow fiscal order.
- Partial cancellation allowed overlapping/duplicate requests and did not adjust paid/unpaid days.
- Substitute could respond after a leave was closed; requester was never told.
- Controller-level leave-type checks contradicted `leave_policies` (policy is now the single source).
- Removed unreachable `ApprovalController::approve/reject` (routes point to `GovernmentApprovalController`).

#### Added
- `App\Services\LeaveApprovalFlow` — one place for current step / next step / who may act.
- National holiday management API `GET|POST|PUT|DELETE /api/admin/holidays` (`leave.calendar.manage`) and a section in ปฏิทินวันทำการ.
- `ThaiGovernmentHoliday2570Seeder` (2027 holidays; ploughing day and special cabinet holidays to be added by admins when announced).
- Notification types `leave.cancellation_requested|approved|rejected`, `leave.substitute_requested|responded`; approvers are notified per step instead of all executives at once.
- Migrations: `2026_10_13_100000` (snapshot resolver fields), `2026_10_13_100100` (notification types), `2026_10_13_100200` (policy defaults per regulation: vacation 20/30-day accumulation, 6-month service for vacation, paternity leave for men — only rows still at seed defaults).
- Tests `Tests\Feature\LeaveLifecycleFixesTest` (15 tests); fixed 7 pre-existing tests that broke after the 1 Oct fiscal-year rollover.

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
