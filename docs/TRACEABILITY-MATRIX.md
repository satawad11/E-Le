# TRACEABILITY MATRIX (REBUILD-2569)

> เริ่มใหม่ 2026-10-18 · agent อัปเดตเมื่อจบแต่ละ task · ตารางเดิมดูได้จาก tag `legacy-final`

| REQ | Implementation (ไฟล์หลัก) | Test | สถานะ |
|---|---|---|---|
| REQ-ORG-01 | `Services/Org/OrgTree` (path, depth, level rule, nearestOfLevel, headOf) · schema R02-3 · seed `OrganizationBaselineSeeder` | `Core/OrgTreeTest`, `Core/MultiPositionPersonnelTest`, `Rebuild/BaselineSeederTest` | ✅ service (R-03) · UI R-06 |
| REQ-ORG-02 | `positions.kind/org_unit_type_id` · `AssignmentService::assertPositionFits` · `Org/PositionController` | `Core/AssignmentServiceTest::test_management_position_must_match_the_unit_type` | ✅ service (R-03) · API เปิด R-04 |
| REQ-ORG-03 | `OrgTree::move`, `OrgTree::close` (ปิดแทนลบ ไม่มี endpoint ลบ) | `Core/OrgTreeTest` | ✅ service (R-03) |
| REQ-PER-01 | models `Personnel*` · `FieldClassificationSeeder` | `Rebuild/SchemaBaselineTest`, `Core/PersonnelPrivacyTest::test_every_presented_field_has_a_classification` | 🟡 schema+model (R-03) · หน้าจอ R-06 |
| REQ-PER-02 | `Services/Personnel/AssignmentService` · `Events/AssignmentChanged` | `Core/AssignmentServiceTest`, `Core/MultiPositionPersonnelTest` | ✅ service (R-03) |
| REQ-PER-03 | `PersonnelPresenter`, `FieldClassificationRegistry`, `Contracts/Personnel/PersonnelFieldAccess` | `Core/PersonnelPrivacyTest` (L1–L5) | 🟡 presenter (R-03) · สิทธิ์จริง R-04 |
| REQ-PER-04 | `SensitiveReveal` → `sensitive_access_logs` | `Core/PersonnelPrivacyTest::test_reveal_*` | ✅ service (R-03) · หน้าประวัติการเข้าถึง R-06 |
| REQ-PER-05 | `PersonnelChangeRequestService`, `PersonnelEditor` (hr_field_guard) | `Core/PersonnelChangeControlTest` | ✅ service (R-03) · หน้าจอ R-06 |
| REQ-PER-06 | `Services/Import/PersonnelImporter`, `personnel:import`, `personnel:import-template`, `Support/Spreadsheet/*` | `Core/PersonnelImportTest` | 🟡 ตัวนำเข้าพร้อม · รอไฟล์จริงจากงานบุคคล |
| REQ-AZ-01 | schema: `modules`, `module_features`, `permissions` (retired_at) · seeders ไม่สร้าง permission | `Rebuild/BaselineSeederTest::test_baseline_seeds_no_accounts_roles_permissions_or_menus` | 🟡 schema (R-02) |
| REQ-AZ-02 | schema: `template_permissions.scope`, `position_templates`, `role_grants` | `Rebuild/SchemaBaselineTest` | 🟡 schema (R-02) |
| REQ-AZ-03 | `DenyUntilPermissionEngine`, `RequirePermission` · `permission_overrides` · `role_grants.expires_at` NOT NULL | `Rebuild/DenyByDefaultTest`, `Rebuild/SchemaBaselineTest::test_role_grants_require_order_number_and_expiry` | 🟡 deny-all ชั่วคราว (R-02) · engine R-04 |
| REQ-AZ-04 | | | ⬜ |
| REQ-AZ-05 | ถอด allow-all ก่อน Gate (`AppServiceProvider`) · ถอด Super Admin bypass ใน approver/signer | `Rebuild/DenyByDefaultTest::test_no_ability_is_granted_without_permission_engine`, `Rebuild/ForbiddenPatternsTest` | 🟡 (R-02) |
| REQ-AZ-06 | | | ⬜ |
| REQ-AZ-07 | | | ⬜ |
| REQ-MENU-01 | | | ⬜ |
| REQ-ACC-01 | schema: `users.status`, `locked_until`, `separated_at`, `account_type` · `UserObserver` ตัด token · `AuthController` เฉพาะ active | `Rebuild/CoreModelsTest::test_login_by_email_or_national_id_for_active_accounts_only`, `::test_tokens_are_revoked_when_account_leaves_active_status` | 🟡 schema (R-02) · JML R-05 |
| REQ-ACC-02 | schema: `mfa_credentials` (encrypted) | `Rebuild/CoreModelsTest::test_mfa_secrets_are_encrypted_and_hidden` | 🟡 schema (R-02) |
| REQ-ACC-03 | schema: `access_reviews`, `access_review_items` | `Rebuild/SchemaBaselineTest` | 🟡 schema (R-02) |
| REQ-WF-01 | ตัด `approval_steps`, `workflow_shadow_comparisons`, คอลัมน์ role ของ workflow · `approver_role_fallback` = false | `Rebuild/SchemaBaselineTest` | 🟡 (R-02) · resolver R-04 |
| REQ-WF-02 | | | ⬜ |
| REQ-AUD-01 | schema: `sensitive_access_logs`, `permission_audit_logs`, `login_events` (ไม่มี updated_at) · trait `AppendOnly` | `Rebuild/SchemaBaselineTest::test_append_only_logs_have_no_updated_at`, `Rebuild/CoreModelsTest` | 🟡 schema (R-02) · หน้าจอ R-07 |
| REQ-MOD-01 | ตารางโมดูลเดิม 69 ตารางใน `r02_7a..7i` · `department_id` → `org_unit_id` | `Rebuild/SchemaBaselineTest::test_all_kept_module_tables_exist`, `::test_department_references_became_org_unit_references` | 🟡 schema (R-02) · โค้ดโมดูล R-08 |
