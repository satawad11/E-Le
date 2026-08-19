# TRACEABILITY MATRIX
เอกสารนี้ต้องอัปเดตระหว่างพัฒนา

| Requirement | Spec | Implementation | Test | Status |
|---|---|---|---|---|
| REQ-CORE-001 | 08-MODULE-STANDARDS, CORE specs | TBD | TBD | Planned |
| REQ-PER-001 | CORE-02-PERSONNEL | TBD | TBD | Planned |
| REQ-LEAVE-002 | LEAVE-01-OVERVIEW | TBD | TBD | Planned |
| REQ-ATT-001 | TIME-01-WORK-SCHEDULE | TBD | TBD | Planned |
| REQ-ATT-002 | ATT-02-DEVICE-INTEGRATION | TBD | TBD | Planned |
| REQ-NOT-001 | NOTIFY-01-NOTIFICATION · LEGACY-MIGRATION-PLAN 10-2..10-6 | Notification Center event/recipient/delivery ledger, versioned templates, preferences, provider adapters, retry/backoff | NotificationCenterTest + full regression | Verified |
| LEGACY-01-BACKUP | LEGACY-MIGRATION-PLAN 01-0 | Spatie backup, private disk, scheduler/worker | backup run + isolated restore drill | Verified |
| LEGACY-01-CI | LEGACY-MIGRATION-PLAN 01-6 | `.github/workflows/ci.yml` | Pint, 130 tests, MySQL 8 fresh migration, Vite build | Verified |
| LEGACY-01-OBS | LEGACY-MIGRATION-PLAN 01-7/01-8 | production guard, structured log, request context middleware | ProductionEnvironmentGuardTest, RequestContextTest | Verified |
| LEGACY-01-LEAVE | LEGACY-MIGRATION-PLAN 01-9/01-10 | permission authorization, checked status varchar | LeaveTest, GovernmentLeaveGovernanceTest | Verified |
| LEGACY-01-REFDATA | LEGACY-MIGRATION-PLAN 01-11 | FiscalYearSeeder, ThaiGovernmentHoliday2569Seeder, DocumentTemplateSeeder | BaselineReferenceDataSeederTest | Verified |
| LEGACY-02-FILES | LEGACY-MIGRATION-PLAN 02-A | private_uploads, SecureFileAccessService, nginx storage deny, attendance MIME validation | SecureFileAuthorizationTest, AttendanceImageSecurityTest | Verified |
| LEGACY-02-AUTH | LEGACY-MIGRATION-PLAN 02-C | account lockout, Sanctum expiry/rotation/revocation, reset flow, production session defaults | AuthenticationHardeningTest, AuthTest, frontend build | Verified |
| LEGACY-02-SIGN | LEGACY-MIGRATION-PLAN 02-D | mandatory password confirmation, assigned signer policy, sealed document hash, verify throttle | Saraban SignatureTest | Verified |
| LEGACY-02-GENERAL | LEGACY-MIGRATION-PLAN 02-E | security headers, sandboxed template preview, escaped pagination, generic errors, export/download throttles | SecurityHeadersTest, RouteAuthorizationAuditTest, frontend build, nginx -t | Verified |
| LEGACY-03-ORG-PER | LEGACY-MIGRATION-PLAN 03-1..03-5 | org_units, department map, users.org_unit_id, personnel_assignments, public UUIDs | CoreDataFoundationTest | Verified |
| LEGACY-03-AUDIT | LEGACY-MIGRATION-PLAN 03-6..03-7 | central append-oriented audit_logs, legacy dual-write, user/attendance/role/permission/settings audit | CentralAuditLogTest + existing audit regressions | Verified |
| LEGACY-03-TEMPLATE | LEGACY-MIGRATION-PLAN 03-8 | document_template_versions, effective dating, leaves version pin | DocumentTemplateVersionTest, LeaveExportTest | Verified |
| LEGACY-04-AUTHZ | LEGACY-MIGRATION-PLAN 04-1..04-3, 04-5 | permission_aliases, permission_denies, permission_scopes, PermissionEvaluator, OrgScopeResolver | PermissionEvaluatorTest, OrgScopeResolverTest | Verified |
| LEGACY-04-PERMS | Decision 1 (2026-08-14) | OperationalPermissionSeeder + migration 2026_08_14_100000 — permission 7 ตัวที่ route บังคับใช้ พร้อมการแจกตามหน้าที่ | OperationalPermissionMatrixTest, AuthorizationMatrixTest | Verified |
| LEGACY-04-SARABAN | Decision 2 (2026-08-14) | `saraban.document.register` แทน hasRole ใน DocumentPolicy/DocumentController/DocumentWorkflowService และหน้าเว็บ | SarabanTestCase suite (39 tests), OperationalPermissionMatrixTest | Verified |
| LEGACY-04-MANAGER | Decision 3 (2026-08-14) | org_unit_managers แบบ effective-dated + OrgScopeResolver::managedOrgUnitIds() | OrgScopeResolverTest | Verified |
| LEGACY-04-SCOPE | Decision 4 (2026-08-14) | ขอบเขต Director/Deputy/Manager ตาม ADR-007 ผ่าน permission_scopes + org_unit_managers | OrgScopeResolverTest | Verified |
| LEGACY-04-LEASTPRIV | Security Rule (2026-08-14) | แยก admin.users.manage_permissions / system.menu.manage และ /api/saraban/settings | PrivilegeEscalationGuardTest | Verified |
| LEGACY-05-SCHEMA | LEGACY-MIGRATION-PLAN 05-1, docs/07 | workflow_definitions/versions/steps/transitions/instances/tasks/actions/delegations + shadow comparisons | WorkflowEngineTest | Verified |
| LEGACY-05-LEAVE-V1 | LEGACY-MIGRATION-PLAN 05-2 | LeaveWorkflowProvisioner แปลง approval_steps เป็น Leave Workflow v1 พร้อม version pinning | WorkflowEngineTest (version pinning) | Verified |
| LEGACY-05-SHADOW | LEGACY-MIGRATION-PLAN 05-3 | LeaveWorkflowShadow + workflow:shadow-replay / shadow-report บน GovernmentApprovalController | LeaveWorkflowShadowParityTest | Verified |
| LEGACY-05-CUTOVER | LEGACY-MIGRATION-PLAN 05-4 | config workflow.engine_is_source_of_truth (ยังไม่เปิด และยังไม่มีโค้ดอ่าน) | — | Deferred |
| LEGACY-05-SARABAN | LEGACY-MIGRATION-PLAN 05-5 | SarabanWorkflowProvisioner (draft เท่านั้น ไม่แตะสิทธิ์) | WorkflowAuthorizationTest | Prepared |
| LEGACY-05-DELEGATION | งานหลัก 4 | DelegationResolver + workflow_delegations mirror จาก user_delegations | WorkflowEngineTest (delegate/expired) | Verified |
| LEGACY-05-AUDIT | งานหลัก 6 | workflow_actions append-only + central audit dual-write | WorkflowEngineTest (audit completeness) | Verified |
| LEGACY-05-READONLY | LEGACY-MIGRATION-PLAN 05-7 | approval_steps / leave_approvals คงไว้เป็น read-only | LeaveWorkflowShadowParityTest, WorkflowAuthorizationTest | Verified |
| CORE-CP-P0 | CORE CHECKPOINT | P0 gaps 16/16 ปิดครบ | 49 test class · composer audit สะอาด | Verified |
| CORE-CP-P1 | CORE CHECKPOINT | P1 gaps 18/18 ปิดครบ | ดู 3 แถวล่าง | Verified |
| CORE-CP-DEPLOY | DEPLOY-001 / DEPLOY-004 | nginx + php-fpm เป็นทางเข้าเดียว · Dockerfile ชุดเดียว · `docker/nginx/default.conf` ใช้งานจริง | ตรวจ HTTP จริง: `/api/ping` 200, `/storage` 404, backend ไม่เปิดพอร์ต | Verified |
| CORE-CP-SARABAN-WF | CORE-WF-002 | เอกสารสารบรรณ pin `workflow_version_id` ผ่าน SarabanWorkflowProvisioner | SarabanWorkflowPinningTest | Verified |
| CORE-CP-SARABAN-AUTHZ | CORE-WF-002 (ต้นทาง) | ผอ./รอง ผอ. ได้ `saraban.document.approve` / `.sign` โดยไม่ได้สิทธิ์ลบ/เอกสารลับ/ตั้งค่า | OperationalPermissionMatrixTest | Verified |
| LEGACY-06-SCHEDULE | LEGACY-MIGRATION-PLAN 06-1 · TIME-SCH-001 | work_schedules + work_schedule_days + work_schedule_assignments (effective-dated, กะ, กะข้ามวัน, timezone) | TimeServiceTest | Verified |
| LEGACY-06-BACKFILL | LEGACY-MIGRATION-PLAN 06-2 | WorkScheduleSeeder สร้างตารางเริ่มต้นจาก setting เดิม โดยไม่แตะ users.work_start_time | WorkScheduleBackfillTest | Verified |
| LEGACY-06-GRACE | LEGACY-MIGRATION-PLAN 06-3 | grace_minutes / early_leave_grace_minutes ใน work_schedules | TimeServiceTest (grace period) | Verified |
| LEGACY-06-CALENDAR | LEGACY-MIGRATION-PLAN 06-4 · TIME-CAL-001 | WorkingCalendar เชื่อม holidays + work_calendar_exceptions เข้ากับ engine (ไม่สร้างใหม่) | TimeServiceTest (holiday/exception) | Verified |
| LEGACY-06-CENTRAL | โจทย์ LEGACY-06 | TimeService เป็นทางเข้าเดียว · LeaveService และ AttendanceController เลิกคำนวณเอง | TimeServiceTest + full regression | Verified |
| LEGACY-07-TEMPLATE | LEGACY-MIGRATION-PLAN 07-1 · LEAVE-003 | LeaveDocumentTemplateResolver เรนเดอร์จากเวอร์ชันที่ pin ไว้ + pin ตามแบบฟอร์มจริง | LeaveModernizationTest (template version) | Verified |
| LEGACY-07-EVIDENCE | LEGACY-MIGRATION-PLAN 07-4 · LEAVE-004 | ผู้อนุมัติเข้าถึงหลักฐานได้ผ่าน LeavePolicy::view() โดยไม่ขยายขอบเขต | LeaveEvidenceAccessTest | Verified |
| LEGACY-07-COUNTS | LEGACY-MIGRATION-PLAN 07-3 · LEAVE-001 | รายงานนับจำนวนครั้งแยกจากจำนวนวัน | LeaveModernizationTest (occurrences vs days) | Verified |
| LEGACY-07-DOCYEAR | LEGACY-MIGRATION-PLAN 07-5 · SARABAN-001 | ฐานปีเลขที่ใบลาตาม period_basis · ตรงกับสารบรรณเมื่อใช้ฐานปีงบ | LeaveModernizationTest (document number year) | Verified |
| LEGACY-07-TIMELINK | โจทย์ LEGACY-07 | calculateTotalDays() ส่ง user เข้า TimeService — ตารางเฉพาะบุคคลมีผลกับการนับวันลา | LeaveModernizationTest (personal schedule) | Verified |
| LEGACY-07-WORKFLOW | โจทย์ LEGACY-07 | ใบลาใหม่เปิด workflow instance (shadow) พร้อม pin version | LeaveModernizationTest (shadow instance) | Verified |
| CORE-CP-BACKUP | CORE CHECKPOINT | backup อัตโนมัติ + restore drill 73 ตาราง mismatch 0 | `restore-verification-core-checkpoint.txt` | Verified |
| CORE-CP-RECON | CORE CHECKPOINT | data reconciliation 55 → 73 ตาราง อธิบายได้ครบ | `rowcount-baseline-2026-08-12T22.txt` เทียบปัจจุบัน | Verified |

| LEGACY-07-2-REF | Leave controller responsibility split with endpoint compatibility | `LeaveQueryController`, `LeaveRequestController`, `LeaveCancellationController`, `LeaveWorkflowActionController`, `LeaveDocumentController`, FormRequests | `LeaveControllerSplitTest`, `LeaveAuthorizationTest`, full suite | PASS |
| LEGACY-08-SOURCES | LEGACY-MIGRATION-PLAN 08-1..08-4 · ATT-INT-001/002 | `attendance_sources`, `devices`, `attendance_identity_maps`, `attendance_raw_events`; UUID/dedup uniqueness; vendor-neutral connector contracts | `AttendanceIntegrationTest` duplicate UUID/dedup + immutable payload | Verified |
| LEGACY-08-ENGINE | LEGACY-MIGRATION-PLAN 08-6..08-8 | `AttendanceEventRecorder` + `AttendanceReconciliationService`; existing `attendances` remains one daily result row | duplicate, out-of-order, overnight, missing checkout, web check-in/out tests | Verified |
| LEGACY-08-CORRECTION | LEGACY-MIGRATION-PLAN 08-9 · ATT-001 | `attendance_corrections` + active generic Workflow Engine instance + explicit audit on request/decision/apply | manual correction workflow/audit test | Verified |
| LEGACY-08-AUTHZ | Security gates | exact check-in permission + `AttendanceScopeService` over org scope for personnel lists/reports/exports and correction IDOR | permission/scope tests + AuthorizationMatrix full regression | Verified |
| LEGACY-08-VENDOR | LEGACY-MIGRATION-PLAN 08-5 · Open Question #1/#2 | `AttendanceConnector` / `BiometricConnector` contracts only; no assumed protocol or identifier | compilation + full regression | Prepared; connector deferred |
| LEGACY-09-ORDER | LEGACY-09 requirements | `ot_orders` + `OvertimeOrderService` + non-shadow `overtime.order` workflow | `OvertimeCriticalPathTest` approval/reject/pending assignment | Verified |
| LEGACY-09-ASSIGN | LEGACY-09 requirements | `ot_assignments`; approved order required; assignee must be inside order org scope | critical path + pending/scope tests | Verified |
| LEGACY-09-TIME | Business rule approved != actual != certified | approved window on order; actual/certified windows and minutes on `ot_time_records` | 120 approved / 90 actual / 60 certified regression | Verified |
| LEGACY-10-CENTER | LEGACY-MIGRATION-PLAN 10-3 | notification types/templates/events/recipients/deliveries/preferences | NotificationCenterTest schema/event/recipient tests | Verified |
| LEGACY-10-CHANNELS | LEGACY-MIGRATION-PLAN 10-4 | In-App/Email adapters + config-gated LINE OA/Telegram; compatibility proxy for old Telegram service | Email/send/skip tests + direct provider scan | Verified |
| LEGACY-10-RETRY | LEGACY-MIGRATION-PLAN 10-5/10-6 | per-channel status, exponential retry, terminal failed state; provider failure isolated from event transaction | bounded retry/provider isolation tests | Verified |
| LEGACY-10-AUTHZ | Security gates | owner-scoped list/read/preferences; Attendance/OT/Saraban recipients pre-filtered by permission/scope | IDOR/read scope + module regression | Verified |
| LEGACY-10-COMPAT | Compatibility requirement | dual-read/mark-read Laravel notifications + center ledger; frontend response contract unchanged | legacy notification compatibility test + frontend build | Verified |
| LEGACY-09-RECON | Attendance reconciliation | `OvertimeAttendanceReconciliationService`; legacy fields read-only; legacy rows backfilled with source=`legacy` | reconciliation + read-only tests; MySQL 8 2/2 links | Verified |
| LEGACY-09-REPORT | Reports + permission/scope | `/api/overtime/reports`; explicit approved/actual/certified values; existing OT summary reads new domain | report/scope and export regression | Verified |
| LEGACY-09-NOPAY | No compensation without policy | no rate/amount/pay/compensation schema or service | schema assertion | Verified |
| LEGACY-11-DATA | Personnel/Leave/Balance/Attendance/Late/Absence/OT/Saraban/Executive reports | unified `ReportingDataService` + `ReportDataset` | ReportingLayerTest dataset regressions | Verified |
| LEGACY-11-FILTER | Fiscal year, org, employee, personnel type, date range | `ReportFilter` + scoped user subquery | date and cross-org scope regression | Verified |
| LEGACY-11-EXPORT | PDF, Excel, Word and CSV where supported | one scoped dataset feeds all four adapters; exports audited | PDF signature/content type, XLSX/DOCX download, CSV contents | Verified |
| LEGACY-11-SECURITY | Normal permission + organization scope; no export over scope | `ReportingScopeService`; legacy Government/Saraban queries scope-hardened | denial, out-of-scope 403, hidden employee tests | Verified |
| LEGACY-11-EVAL | Statistics layer without undefined formula | `EvaluationMetricsService` exposes facts only, `formula=null` | no score/pass fields regression | Verified |
| LEGACY-11-PERF | Query performance and N+1 prevention | SQL aggregates, eager loading, bounded personnel query count | 20-user query-budget regression | Verified |
| LEGACY-12-CONTRACT | New modules declare full extension surface | `ModuleContract`, `ModuleDefinition`, validated manifests | complete-field assertions for every current module | Verified |
| LEGACY-12-DEPS | Core has no dependency back to Leave/Attendance/OT | module-owned providers + generic registry/middleware/Workflow Engine boundary | forbidden-import architecture scan | Verified |
| LEGACY-12-TOGGLE | Modules enable/disable safely with dependencies | deployment flags, transitive effective state, route 404, route-file loading gate | disabled module/dependency HTTP and registry tests | Verified |
| LEGACY-12-MENU | Menu from permission; backend does not trust menu | `MenuService` module + permission filter; route middleware/policies unchanged | visible menu with unrelated permission still gets backend 403 | Verified |
| LEGACY-12-NAMING | New permission names follow standard | registry requires `module.resource.action`; explicit legacy compatibility only | valid/invalid manifest tests | Verified |
| LEGACY-12-GUIDE | Migration/module registration is repeatable | `docs/MODULE-EXTENSION-GUIDE.md` | documented checklist + contract regression | Verified |
| UX-01-LAYOUT | Standardize AppShell, mobile drawer, z-index, and responsive layout | `App.vue`, `ForgotPasswordView.vue`, `ResetPasswordView.vue`, 10 views | Frontend build (879 modules) + Backend regression (462 tests) | Verified |
| UX-02-NAV | Navigation, breadcrumb, 7-role matrix audit, route sync and dead-link elimination | `router/index.js`, `App.vue` | Frontend build (879 modules) + Backend regression (462 tests) | Verified |
| UX-03-RESPONSIVE | Responsive audit across 7 breakpoints (360-1920px), table scrolling, fluid type and modal overflow | 10+ views, modal/tables/cards | Frontend build (879 modules) + Backend regression (462 tests) | Verified |
| UX-04-MOBILE | Mobile UX optimization, 44px touch targets, iOS zoom prevention, segmented chips, thumb CTAs | `DashboardView.vue`, `LoginView.vue`, `LeaveRequestView.vue`, `ApprovalsView.vue`, `MyLeavesView.vue`, `NotificationBell.vue` | Frontend build (879 modules) + Backend regression (462 tests) | Verified |
| UX-05-FORMS | Form & validation audit, 422 error extraction, double submit prevention, zero native alerts, confirmation dialogs | `ApprovalsView.vue`, `MyLeavesView.vue`, `LeavePoliciesView.vue`, `UsersManagementView.vue`, `UserProfileView.vue`, `DepartmentsManagementView.vue`, `FiscalYearManagementView.vue`, `LeaveTypesManagementView.vue`, `WorkflowTemplatesView.vue`, `DocumentTemplatesView.vue`, `DocumentDetailView.vue`, `ReportsView.vue`, `AttendanceReportView.vue`, `LeaveManagementView.vue` | Frontend build (878 modules) + Backend regression (462 tests, 1370 assertions) | Verified |
| UX-06-STATES | UI State standardization, reusable Loading/Empty/Error/PermissionDenied components, 401/403/404 handling, safe Thai error scrubber | `LoadingState.vue`, `EmptyState.vue`, `ErrorState.vue`, `PermissionDenied.vue`, `NotFoundView.vue`, `ForbiddenView.vue`, `errorHandler.js`, async views | Frontend build (885 modules) + Backend regression (462 tests, 1370 assertions) | Verified |
| UX-07-A11Y | Accessibility audit (WCAG 2.2 AA), skip to main content, dialog roles, escape handlers, form label associations, keyboard triggers, focus rings, aria-labels | `style.css`, `App.vue`, `NotificationBell.vue`, `ThaiDatePicker.vue`, `UserPermissionModal.vue`, `LoginView.vue`, `LeaveRequestView.vue`, `ApprovalsView.vue`, `CheckInView.vue`, `UserProfileView.vue` | Frontend build (885 modules) + Backend regression (462 tests, 1370 assertions) | Verified |
| UX-08-PERMISSIONS | Permission UX audit, Zero-Trust frontend, direct URL 403 route protection, dynamic menu/action gating, IDOR checks, live permission invalidation | `router/index.js`, `auth.js`, `App.vue`, `UserPermissionModal.vue`, `ForbiddenView.vue`, policies/guards | Frontend build (885 modules) + Backend regression (462 tests, 1370 assertions) | Verified |
| UX-09-WORKFLOW | Workflow UX audit, multi-tier stepper, current step pulsating node, rejection/cancellation alert banner, next step guidance, mobile timeline | `WorkflowTimeline.vue`, `MyLeavesView.vue`, `ApprovalsView.vue`, `DocumentDetailView.vue` | Frontend build (887 modules) + Backend regression (462 tests, 1370 assertions) | Verified |
| UX-10-DESIGN-SYSTEM | Design system consolidation, canonical component suite (AppButton, AppBadge, AppModal, AppPagination, AppInput, AppSelect, AppTextarea), selective inconsistency fixes | `components/ui/`, `LeavePoliciesView.vue`, `UsersManagementView.vue`, `DocumentDetailView.vue`, `RolesManagementView.vue`, `DashboardView.vue` | Frontend build (887 modules) + Backend regression (462 tests, 1370 assertions) | Verified |
| UX-11-CROSS-MODULE | Cross-module UX consistency audit, date formatting standardization (Thai Buddhist Era), Thai wording alignment | `UsersManagementView.vue`, `FiscalYearManagementView.vue`, `WorkCalendarView.vue`, `DocumentDetailView.vue`, `LeavePoliciesView.vue`, `ThaiDateFormatter.js` | Frontend build (887 modules) + Backend regression (462 tests, 1370 assertions) | Verified |
| UX-12-FINAL-E2E | Final E2E UX verification, 11 user journeys across real roles, multi-viewport audit, 0 critical issues, production readiness PASS | All frontend views, components, router, stores, backend feature tests | Frontend build (887 modules) + Backend regression (462 tests, 1370 assertions) | Verified |
| APPROVAL-WORKFLOW-FIX | Approval workflow management audit & fix, sequence validation, shadow sync, optimistic concurrency (409), pinned snapshot integrity, delete modal, unsaved changes guard | `WorkflowController.php`, `WorkflowManagementView.vue`, `LeaveWorkflowProvisioner.php`, `LeaveService.php` | `ApprovalWorkflowManagementTest.php` (9 tests, 44 assertions), Frontend build (887 modules) | Verified |
| USER-00-IMPACT-ANALYSIS | Deep audit of 68 FKs, Auth/Session/Spatie dependencies, data flow analysis, migration strategy | `docs/checkpoints/USER-00-IMPACT-ANALYSIS.md` | Static audit, schema analysis | Verified |
| USER-01-PERSONNEL-FOUNDATION | Personnel entity foundation (Additive), 1:1 User-Personnel binding, idempotent backfill, PersonnelAssignment helpers, ApprovalController Spatie cleanup | `personnel` migration, `Personnel.php`, `User.php`, `PersonnelAssignment.php`, `ApprovalController.php` | `PersonnelEntityFoundationTest.php` + full regression | Verified |
| USER-02-DUAL-WRITE | Dual-Write & Observer synchronization, loop-safe recursion guard, atomic transactions, effective dating history management | `PersonnelSyncService.php`, `UserObserver.php`, `PersonnelObserver.php`, `UserProfileObserver.php`, `PersonnelAssignmentObserver.php`, `AppServiceProvider.php` | `PersonnelSyncTest.php` + full regression | Verified |
| USER-03-CORE-SERVICES-REFACTOR | Core Services refactoring to Personnel Domain (OrgScopeResolver, LeavePolicyService, TimeService, ReportingDataService, LeaveService) | `OrgScopeResolver.php`, `LeavePolicyService.php`, `TimeService.php`, `ReportingDataService.php`, `LeaveService.php` | `PersonnelDomainCoreServicesTest.php` + full regression | Verified |
| USER-04-API-FRONTEND-ALIGNMENT | API and Frontend alignment with Personnel Domain, User model accessors/appends, authStore getters, component displays | `User.php`, `AuthController.php`, `Admin/UserController.php`, `auth.js`, `UsersManagementView.vue`, `UserProfileView.vue` | `PersonnelApiAlignmentTest.php` + full regression | Verified |
| USER-05-AUTH-CLEANUP | Authorization cleanup, hardcoded roles removal, Spatie query fixes, non-regression verification | `MyLeavesView.vue`, `DashboardView.vue`, `UserProfileView.vue`, `ApprovalController.php`, `LeaveRequestNotificationService.php` | `AuthorizationCleanupAndVerificationTest.php` + full regression | Verified |
| APPROVAL-01-RESOLVER-HARDENING | Approval resolver & workflow hardening, 7 resolver types, org_unit_managers integration, immutable snapshot, concurrency 409 | `ApproverResolverService.php`, `WorkflowController.php`, `ApprovalController.php`, `GovernmentApprovalController.php`, `WorkflowManagementView.vue` | `ApprovalResolverAndWorkflowHardeningTest.php` + full regression | Verified |













| APPROVAL-02-CHAIN-SNAPSHOT | Approval chain fix: resolver snapshot on `leave_approvals`, fail-closed approver resolution, worklist unified with approval authorization, cancellation chain aligned | `2026_08_19_090000_add_resolver_snapshot_to_leave_approvals.php`, `LeaveService.php`, `GovernmentApprovalController.php`, `ApproverResolverService.php`, `ApprovalController.php`, `LeaveCancellationController.php`, `Admin/WorkflowController.php` | `LeaveApprovalChainSnapshotTest.php` (7 tests, 31 assertions) + full regression (514 tests) | Verified |
| APPROVAL-03-CHAIN-PREVIEW | Approver preview for workflow configuration: resolve real approvers per step for a chosen requester, incl. unsaved chains, with empty/self-approval warnings | `Admin/WorkflowController.php` (`preview`), `routes/api.php`, `WorkflowManagementView.vue` | `LeaveApprovalChainSnapshotTest.php` (preview cases) + full regression (517 tests), frontend build | Verified |
