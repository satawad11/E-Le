# TRACEABILITY MATRIX (REBUILD-2569)

> เริ่มใหม่ 2026-10-18 · agent อัปเดตเมื่อจบแต่ละ task · ตารางเดิมดูได้จาก tag `legacy-final`

| REQ | Implementation (ไฟล์หลัก) | Test | สถานะ |
|---|---|---|---|
| REQ-ORG-01 | schema: `r02_3_create_core_organization_tables` (org_unit_types.level, org_units.path/depth) · seed: `OrgUnitTypeSeeder`, `OrganizationBaselineSeeder` | `Rebuild/SchemaBaselineTest`, `Rebuild/BaselineSeederTest` | 🟡 schema (R-02) · service R-03 |
| REQ-ORG-02 | schema: `positions.kind`, `positions.org_unit_type_id` (unique) | `Rebuild/SchemaBaselineTest` | 🟡 schema (R-02) |
| REQ-ORG-03 | | | ⬜ |
| REQ-PER-01 | schema: `r02_4_create_core_personnel_tables` · models `Personnel*` | `Rebuild/SchemaBaselineTest` | 🟡 schema (R-02) |
| REQ-PER-02 | schema: `assignments` (kind, order_number, effective_from/to, ended_by) · model `Assignment` | `Rebuild/CoreModelsTest::test_assignment_effective_on_scope_respects_dates` | 🟡 schema (R-02) |
| REQ-PER-03 | schema: `field_classifications` · `PersonnelProfile` cast encrypted + hidden | `Rebuild/CoreModelsTest::test_sensitive_profile_fields_are_encrypted_at_rest_and_searchable_by_hash` | 🟡 schema (R-02) |
| REQ-PER-04 | schema: `sensitive_access_logs` · model `SensitiveAccessLog` (AppendOnly) | `Rebuild/CoreModelsTest::test_append_only_logs_cannot_be_updated_or_deleted` | 🟡 schema (R-02) |
| REQ-PER-05 | schema: `personnel_change_requests` · model `PersonnelChangeRequest` | `Rebuild/SchemaBaselineTest` | 🟡 schema (R-02) |
| REQ-PER-06 | | | ⬜ |
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
