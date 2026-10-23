# tests/Legacy — เทสต์ของระบบเดิมที่พักไว้ระหว่าง REBUILD-2569

> สร้างใน R-02 (2026-10-19) · ADR-R-010 · คำถามเจ้าของระบบ Q7 ใน `TASKS.md`

R-02 ถอด spatie/laravel-permission, ตาราง `departments`, `user_profiles`, `approval_steps`, `org_unit_managers` ฯลฯ
และให้ทุก route ที่ต้องยืนยันตัวตนตอบ 403 จนถึง R-04 (deny by default) เทสต์ชุดเดิม 85 ไฟล์จึงรันไม่ได้
เพราะ fixture สร้าง role/department/user_profile หรือคาดหวังให้ endpoint ทำงาน

ไฟล์เหล่านี้ **ไม่ได้ถูกลบ** และ **ไม่อยู่ใน testsuite ปกติ** (`phpunit.xml` รันเฉพาะ `tests/Unit`, `tests/Feature`)
แต่ละไฟล์มีเฟสที่ต้องเขียนใหม่บนแบบจำลองใหม่ เมื่อเฟสนั้นมีเทสต์ทดแทนครบแล้วจึงลบไฟล์เดิมออกจากที่นี่
กฎธุรกิจที่ยังมีผล (ระเบียบการลา ADR-R-005, document engine, เวลา) ต้องถูกพอร์ตเป็นเทสต์ใหม่ ห้ามทิ้ง

รันเพื่อดูสถานะได้ด้วย `vendor/bin/phpunit tests/Legacy` (คาดว่าส่วนใหญ่ล้ม)

| กลุ่ม | ไฟล์ | เฟสที่เขียนใหม่ |
|---|---|---|
| ระเบียบการลา (กฎที่ต้องคงไว้) | LeaveEntitlementRulesTest, LeaveEligibilityRulesTest, ThaiGovernmentLeaveTypesTest, PromotionScreeningTest | R-08 leave |
| ใบลา สายอนุมัติ ยกเลิก เอกสาร | ApprovalTest, LeaveTest, LeaveActingOnBehalfTest, LeaveApprovalChainSnapshotTest, LeaveAuthorizationTest, LeaveCancellation{Authorization,Chain,Request}Test, LeaveCardPdfTest, LeaveDeletePermissionTest, LeaveEvidenceAccessTest, LeaveExportTest, LeaveForm{Binding,Data,Designer}Test, LeaveLifecycleFixesTest, LeaveModernizationTest, LeaveRegisterReportTest, LeaveSelfApprovalTest, LeaveVerificationTest, LeaveVisibilityTest, GovernmentLeave{Governance,ReportDepartments}Test, ApprovalResolverAndWorkflowHardeningTest | R-08 leave |
| ลงเวลา | Attendance{ExportAuthorization,ImageSecurity,Integration}Test, Time/TimeServiceTest, Time/WorkScheduleBackfillTest | R-08 attendance (TimeService ส่วนไม่ขึ้นกับสิทธิ์ย้ายเร็วกว่าได้ใน R-03) |
| OT | Overtime{ApprovalChain,CriticalPath,OrderProvisioning}Test | R-08 overtime |
| สารบรรณ | Saraban/* (7 ไฟล์ + SarabanTestCase) | R-08 saraban |
| แจ้งเตือน | NotificationCenterTest | R-08 notification |
| Document engine / รายงาน | DocumentEngineTest, DocumentReportEngineTest, DocumentTemplateSecurityTest, ReportingLayerTest, DashboardReportScopeTest | R-04 (สิทธิ์) + R-08 |
| Workflow engine | Workflow/{ApprovalWorkflowManagement,EngineApproverFidelity,SarabanWorkflowPinning,WorkflowAuthorization,WorkflowEngine}Test | R-04 (resolver) + R-08 |
| บัญชีและการยืนยันตัวตน | RateLimitBucketIsolationTest (เหลือเฉพาะถังจำกัดอัตราของเอกสารใบลา/รายงาน — ย้ายพร้อมโมดูลลา) | R-08 leave |
| องค์กร/บุคลากร (R-06 ทบทวนแล้ว: ส่วนที่เหลือเป็นของโมดูลลา/ลงเวลาและ R-07) | PersonnelDomainCoreServicesTest, PositionCatalogTest (เมื่อ API ตำแหน่งเปิดใน R-04), PersonalDataProtectionTest, SecureFileAuthorizationTest | R-04 / R-06 |
| ตั้งค่าระบบ audit โมดูล | SystemSettingsTest, CentralAuditLogTest, ModuleRegistryTest (ทะเบียนโมดูลธุรกิจเดิม `config/modules.php`) | R-07 / R-08 |

## บันทึกการย้าย/ลบ (Q7 — ทุกเฟสต้องบันทึก)

| เฟส | ไฟล์ | การจัดการ | เทสต์ใหม่ที่ครอบคลุมเจตนาเดิม |
|---|---|---|---|
| R-02 | 85 ไฟล์ | ย้ายมาพักที่นี่ | — |
| R-03 | PersonnelEntityFoundationTest | ลบ | `Rebuild/SchemaBaselineTest`, `Rebuild/CoreModelsTest`, `Core/PersonnelPrivacyTest` |
| R-03 | CoreDataFoundationTest | ลบ (dual-write departments ↔ org_units ถูกตัดตามแผน) | `Rebuild/SchemaBaselineTest`, `Core/OrgTreeTest` |
| R-03 | PersonnelSyncTest | ลบ (การซิงก์ users ↔ personnel ถูกตัด — บุคลากรเป็นแหล่งความจริง) | `Core/AssignmentServiceTest`, `Core/MultiPositionPersonnelTest` |
| R-03 | SchoolStructureCommandTest | ลบ (คำสั่ง org:apply-structure ถูกตัด) | `Rebuild/BaselineSeederTest`, `Core/PersonnelImportTest` |
| R-03 | UserImportTest | ลบ | `Core/PersonnelImportTest` (เข้ารหัสเลขประจำตัว + hash, ซ้ำในระบบ/ในไฟล์, ไม่รับรหัสผ่าน/ไม่สร้างบัญชี, รายงานเลขแถว, audit, คอลัมน์ที่จำเป็น) · สิทธิ์: `Core/CoreApiDeniedUntilEngineTest` |
| R-04 | AccessGovernance2569Test, AccessGovernancePhase2Test | ลบ (ทะเบียนหัวหน้า org_unit_managers, user_role_grants, ขั้นอนุมัติตามชื่อ role ถูกตัด) | `Authz/PersonaMatrixTest`, `Authz/ApproverChainResolverTest`, `Authz/PermissionEngineTest` |
| R-04 | AuthorizationMatrixTest, OperationalPermissionMatrixTest | ลบ (matrix ของ spatie role) | `Authz/PersonaMatrixTest::test_permission_matrix` (8 persona × 23 permission) |
| R-04 | AuthorizationCleanupAndVerificationTest, RbacCleanupTest, UserDirectPermissionTest | ลบ (alias/สิทธิ์รายบุคคลแบบไม่หมดอายุของ spatie) | `Authz/PermissionEngineTest` (override ต้องมีวันหมดอายุ, deny ชนะ), `Rebuild/ForbiddenPatternsTest` |
| R-04 | PermissionEvaluatorTest, PermissionScopeCommandTest, OrgScopeResolverTest | ลบ (PermissionEvaluator/permission_scopes ถูกแทนด้วย PermissionEngine) | `Authz/PermissionEngineTest::test_scope_query_*`, `test_level_two_rights_do_not_leak_*` |
| R-04 | PrivilegeEscalationGuardTest | ลบ | `Authz/PermissionEngineTest::test_grant_ceiling_and_two_person` |
| R-04 | LegacyModernizationMigrationRollbackTest | ลบ (migration ชุดเดิมไม่ถูกโหลดแล้ว) | `Rebuild/SchemaBaselineTest` |
| R-04 | Workflow/LeaveWorkflowShadowParityTest | ลบ (ยกเลิก shadow — ADR-R-006) | `Authz/ApproverChainResolverTest` |
| R-05 | AuthTest | ลบ | `Access/LoginSecurityTest` (เข้าสู่ระบบ/ผิดรหัส/ออกจากระบบ/token มีวันหมดอายุ) · `Rebuild/CoreModelsTest`, `Rebuild/DenyByDefaultTest` (`/api/user`, ไม่ยืนยันตัวตน = 401) |
| R-05 | AuthenticationHardeningTest | ลบ | `Access/LoginSecurityTest` (ล็อก 5 ครั้ง 30 นาที, refresh หมุน token, เปลี่ยน/รีเซ็ตรหัสผ่านตัด token, ลืมรหัสผ่านไม่บอกว่ามีบัญชี) · `Access/AccountsApiTest` (ระงับบัญชีตัด token) |
| R-05 | UserAccountGovernanceTest | ลบ (จัดการผู้ใช้แบบ role/department ถูกตัด) | `Access/AccountsApiTest` (ระงับมี audit และตัด API, จัดการบัญชีตนเองไม่ได้, ไม่มี endpoint ลบบัญชี) · `Access/AccountLifecycleTest` (สร้างบัญชีมี audit ไม่มี hash รหัสผ่าน) · `Access/AccessGrantApiTest` (เปลี่ยนสิทธิ์มี audit ก่อน/หลัง) · `Access/LoginSecurityTest` (เปลี่ยนรหัสผ่านต้องใส่รหัสเดิม) |
| R-05 | PersonnelApiAlignmentTest | ลบ (อ้าง AccountSeparationService และ /admin/users เดิม) | `Access/MfaTest::test_user_outside_required_groups_logs_in_without_mfa` (payload บุคลากรตอนเข้าสู่ระบบ) · `Access/AccountsApiTest` (รายการบัญชีพร้อมรหัสบุคลากร) |
| R-05 | Unit/UserTest | ลบ (คุณลักษณะชื่อ/แผนก/role ของ User เดิมถูกตัด — ชื่ออยู่ที่ Personnel) | `Rebuild/CoreModelsTest` (ความสัมพันธ์ User ↔ Personnel) |
| R-06 | PersonnelDomainCoreServicesTest | คงไว้ ย้ายเฟสเป็น R-08 leave | ทดสอบ OrgScopeResolver/LeavePolicyService/ReportingDataService ของโมดูลลาเดิม ซึ่งยังไม่ย้าย |
| R-06 | PositionCatalogTest | คงไว้ ย้ายเฟสเป็น R-07 (R07-9) และ R-08 leave | ส่วนตำแหน่งครอบคลุมแล้วที่ `Core/AssignmentServiceTest` + API `/api/org/positions` (ไม่มี endpoint ลบ, audit) · ที่เหลือ: กลุ่มตำแหน่ง คำสั่งจับคู่ ทะเบียน/รายงานราชการ |
| R-06 | PersonalDataProtectionTest | คงไว้ ย้ายเฟสเป็น R-07 (R07-8) | ส่วนประวัติการเข้าถึงของตนเองครอบคลุมแล้วที่ `Core/PersonnelScreensApiTest::test_owner_always_sees_their_access_log_and_others_need_reveal_rights` · ที่เหลือ: สำเนาข้อมูลของตนเอง บันทึกการเปิดแบบกลุ่ม ล้างหลักฐานการลา |
| R-06 | SecureFileAuthorizationTest | คงไว้ ย้ายเฟสเป็น R-08 attendance | เอกสารบุคลากรครอบคลุมแล้วที่ `Core/PersonnelScreensApiTest::test_documents_live_on_private_storage_*` · ที่เหลือ: รูปถ่ายโปรไฟล์และภาพลงเวลาของระบบเดิม |
