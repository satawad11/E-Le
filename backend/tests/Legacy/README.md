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
| บัญชีและการยืนยันตัวตน | AuthTest, AuthenticationHardeningTest, RateLimitBucketIsolationTest, UserAccountGovernanceTest, Unit/UserTest | R-05 |
| องค์กร/บุคลากร | PersonnelApiAlignmentTest, PersonnelDomainCoreServicesTest, PositionCatalogTest (เมื่อ API ตำแหน่งเปิดใน R-04), PersonalDataProtectionTest, SecureFileAuthorizationTest | R-04 / R-06 |
| ตั้งค่าระบบ audit โมดูล | SystemSettingsTest, CentralAuditLogTest, ModuleRegistryTest | R-04 / R-07 |
| **ล้าสมัยโดยแผน** (ทดสอบกลไกที่ถูกตัด) | AccessGovernance2569Test, AccessGovernancePhase2Test, AuthorizationCleanupAndVerificationTest, AuthorizationMatrixTest, LegacyModernizationMigrationRollbackTest, OperationalPermissionMatrixTest, OrgScopeResolverTest, PermissionEvaluatorTest, PermissionScopeCommandTest, PrivilegeEscalationGuardTest, RbacCleanupTest, UserDirectPermissionTest, Workflow/LeaveWorkflowShadowParityTest | แทนด้วย persona/IDOR/matrix tests ของ R-04 แล้วลบ (ต้องได้รับอนุมัติ Q7) |

## บันทึกการย้าย/ลบ (Q7 — ทุกเฟสต้องบันทึก)

| เฟส | ไฟล์ | การจัดการ | เทสต์ใหม่ที่ครอบคลุมเจตนาเดิม |
|---|---|---|---|
| R-02 | 85 ไฟล์ | ย้ายมาพักที่นี่ | — |
| R-03 | PersonnelEntityFoundationTest | ลบ | `Rebuild/SchemaBaselineTest`, `Rebuild/CoreModelsTest`, `Core/PersonnelPrivacyTest` |
| R-03 | CoreDataFoundationTest | ลบ (dual-write departments ↔ org_units ถูกตัดตามแผน) | `Rebuild/SchemaBaselineTest`, `Core/OrgTreeTest` |
| R-03 | PersonnelSyncTest | ลบ (การซิงก์ users ↔ personnel ถูกตัด — บุคลากรเป็นแหล่งความจริง) | `Core/AssignmentServiceTest`, `Core/MultiPositionPersonnelTest` |
| R-03 | SchoolStructureCommandTest | ลบ (คำสั่ง org:apply-structure ถูกตัด) | `Rebuild/BaselineSeederTest`, `Core/PersonnelImportTest` |
| R-03 | UserImportTest | ลบ | `Core/PersonnelImportTest` (เข้ารหัสเลขประจำตัว + hash, ซ้ำในระบบ/ในไฟล์, ไม่รับรหัสผ่าน/ไม่สร้างบัญชี, รายงานเลขแถว, audit, คอลัมน์ที่จำเป็น) · สิทธิ์: `Core/CoreApiDeniedUntilEngineTest` |
