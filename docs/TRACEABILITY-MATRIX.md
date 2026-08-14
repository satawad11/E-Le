# TRACEABILITY MATRIX
เอกสารนี้ต้องอัปเดตระหว่างพัฒนา

| Requirement | Spec | Implementation | Test | Status |
|---|---|---|---|---|
| REQ-CORE-001 | 08-MODULE-STANDARDS, CORE specs | TBD | TBD | Planned |
| REQ-PER-001 | CORE-02-PERSONNEL | TBD | TBD | Planned |
| REQ-LEAVE-002 | LEAVE-01-OVERVIEW | TBD | TBD | Planned |
| REQ-ATT-001 | TIME-01-WORK-SCHEDULE | TBD | TBD | Planned |
| REQ-ATT-002 | ATT-02-DEVICE-INTEGRATION | TBD | TBD | Planned |
| REQ-NOT-001 | NOTIFY-01-NOTIFICATION | TBD | TBD | Planned |
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
| CORE-CP-BACKUP | CORE CHECKPOINT | backup อัตโนมัติ + restore drill 73 ตาราง mismatch 0 | `restore-verification-core-checkpoint.txt` | Verified |
| CORE-CP-RECON | CORE CHECKPOINT | data reconciliation 55 → 73 ตาราง อธิบายได้ครบ | `rowcount-baseline-2026-08-12T22.txt` เทียบปัจจุบัน | Verified |
