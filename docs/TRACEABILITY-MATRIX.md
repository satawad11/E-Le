# TRACEABILITY MATRIX (REBUILD-2569)

> เริ่มใหม่ 2026-10-18 · agent อัปเดตเมื่อจบแต่ละ task · ตารางเดิมดูได้จาก tag `legacy-final`

| REQ | Implementation (ไฟล์หลัก) | Test | สถานะ |
|---|---|---|---|
| REQ-ORG-01 | `Services/Org/OrgTree` (path, depth, level rule, nearestOfLevel, headOf) · schema R02-3 · seed `OrganizationBaselineSeeder` | `Core/OrgTreeTest`, `Core/MultiPositionPersonnelTest`, `Rebuild/BaselineSeederTest` | ✅ service (R-03) · UI R-06 |
| REQ-ORG-02 | `positions.kind/org_unit_type_id` · `AssignmentService::assertPositionFits` · `Org/PositionController` | `Core/AssignmentServiceTest::test_management_position_must_match_the_unit_type` | ✅ service (R-03) · API เปิด R-04 |
| REQ-ORG-03 | `OrgTree::move`, `OrgTree::close` (ปิดแทนลบ ไม่มี endpoint ลบ) | `Core/OrgTreeTest` | ✅ service (R-03) |
| REQ-PER-01 | models `Personnel*` · `FieldClassificationSeeder` | `Rebuild/SchemaBaselineTest`, `Core/PersonnelPrivacyTest::test_every_presented_field_has_a_classification` | 🟡 schema+model (R-03) · หน้าจอ R-06 |
| REQ-PER-02 | `Services/Personnel/AssignmentService` · `Events/AssignmentChanged` | `Core/AssignmentServiceTest`, `Core/MultiPositionPersonnelTest` | ✅ service (R-03) |
| REQ-PER-03 | `PersonnelPresenter` + `EnginePersonnelFieldAccess` (internal ← profile.view, confidential ← profile.view_confidential) | `Core/PersonnelPrivacyTest`, `Authz/PermissionEngineTest::test_level_two_rights_do_not_leak_*` | ✅ (R-04) · หน้าจอ R-06 |
| REQ-PER-04 | `SensitiveReveal` → `sensitive_access_logs` | `Core/PersonnelPrivacyTest::test_reveal_*` | ✅ service (R-03) · หน้าประวัติการเข้าถึง R-06 |
| REQ-PER-05 | `PersonnelChangeRequestService`, `PersonnelEditor` (hr_field_guard) | `Core/PersonnelChangeControlTest` | ✅ service (R-03) · หน้าจอ R-06 |
| REQ-PER-06 | `Services/Import/PersonnelImporter`, `personnel:import`, `personnel:import-template`, `Support/Spreadsheet/*` | `Core/PersonnelImportTest` | 🟡 ตัวนำเข้าพร้อม · รอไฟล์จริงจากงานบุคคล |
| REQ-AZ-01 | `app/Modules/*/manifest.php`, `Authz/Manifest/{ModuleRegistry, ManifestValidator, ModuleSynchronizer}`, `modules:sync` | `Authz/ManifestSyncTest`, `Rebuild/BaselineSeederTest` | ✅ (R-04) |
| REQ-AZ-02 | `Authz/PermissionEngine` (grant ต่อแหล่ง · scope ยึดหน่วยของแหล่งนั้น) | `Authz/PermissionEngineTest`, `Authz/PersonaMatrixTest::test_permission_matrix` | ✅ (R-04) |
| REQ-AZ-03 | `EnsureRouteUsesPermissionEngine`, `RequirePermission`, override/template deny · `AuthzScope` (หน่วยที่ถูกตัดต่อแหล่ง) | `Rebuild/DenyByDefaultTest`, `Authz/PermissionEngineTest::test_override_*`, `Authz/ScopeConsistencyTest` | ✅ (R-04) |
| REQ-AZ-04 | `PermissionEngine::ruleViolation` (not_own, maker_checker, hr_field_guard), `canGrant`, `requiresSecondPerson` | `Authz/PermissionEngineTest::test_central_rules`, `test_grant_ceiling_and_two_person`, `Authz/CoreApiAuthorizationTest` | ✅ engine (R-04) · ใช้ตอนมอบสิทธิ์ใน R-05/R-07 |
| REQ-AZ-05 | ไม่มี allow-all/Super Admin bypass · ชุดสิทธิ์ system.admin deny `personnel.profile.reveal` + `*.*.approve`/`*.*.review` (ADR-R-015 แก้ไข) | `Authz/PermissionEngineTest::test_admin_and_auditor_sets_*`, `test_administrator_who_is_also_department_head_*`, `test_department_head_who_is_also_auditor_*`, `Authz/PersonaMatrixTest`, `Rebuild/ForbiddenPatternsTest`, CI `check-authz-patterns.sh` | ✅ (R-04) |
| REQ-AZ-06 | `PermissionEngine::explain()` (ขั้นตอน identity → grants → scope → rules → result) | `Authz/PermissionEngineTest::test_explain_lists_each_decision_step` | 🟡 engine (R-04) · หน้า Simulator R-07 |
| REQ-AZ-07 | | | ⬜ |
| REQ-MENU-01 | `Authz/Menu/MenuBuilder`, `GET /api/me/menu`, manifest `menus`/`menu_groups` | `Authz/MenuSpecTest`, `Authz/PersonaMatrixTest::test_menu_matches_plan_8_3`, `test_menu_names_follow_the_scope`, `test_change_request_badge_*` | ✅ core (R-04) · โมดูลธุรกิจ R-08 |
| REQ-ACC-01 | schema: `users.status`, `locked_until`, `separated_at`, `account_type` · `UserObserver` ตัด token · `AuthController` เฉพาะ active | `Rebuild/CoreModelsTest::test_login_by_email_or_national_id_for_active_accounts_only`, `::test_tokens_are_revoked_when_account_leaves_active_status` | 🟡 schema (R-02) · JML R-05 |
| REQ-ACC-02 | schema: `mfa_credentials` (encrypted) | `Rebuild/CoreModelsTest::test_mfa_secrets_are_encrypted_and_hidden` | 🟡 schema (R-02) |
| REQ-ACC-03 | schema: `access_reviews`, `access_review_items` | `Rebuild/SchemaBaselineTest` | 🟡 schema (R-02) |
| REQ-WF-01 | `Authz/Workflow/ApproverChainResolver` (head_of_level) · `approver_role_fallback` = false | `Authz/ApproverChainResolverTest` | ✅ resolver (R-04) · ต่อ workflow ของการลา R-08 |
| REQ-WF-02 | `ApproverChainResolver` (skip ผู้ยื่น/ชั้นว่าง, merge, acting) | `Authz/ApproverChainResolverTest` | ✅ resolver (R-04) |
| REQ-AUD-01 | schema: `sensitive_access_logs`, `permission_audit_logs`, `login_events` (ไม่มี updated_at) · trait `AppendOnly` | `Rebuild/SchemaBaselineTest::test_append_only_logs_have_no_updated_at`, `Rebuild/CoreModelsTest` | 🟡 schema (R-02) · หน้าจอ R-07 |
| REQ-MOD-01 | ตารางโมดูลเดิม 69 ตารางใน `r02_7a..7i` · `department_id` → `org_unit_id` | `Rebuild/SchemaBaselineTest::test_all_kept_module_tables_exist`, `::test_department_references_became_org_unit_references` | 🟡 schema (R-02) · โค้ดโมดูล R-08 |
