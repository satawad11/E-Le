<?php

namespace Tests\Feature\Rebuild;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R02-12 — schema baseline ของ REBUILD-2569 (PLAN §4.2, TASKS R02-1..R02-7)
 */
class SchemaBaselineTest extends TestCase
{
    use RefreshDatabase;

    /** ตารางที่แผนสั่งตัด — ต้องไม่มีอยู่หลัง migrate */
    public const REMOVED_TABLES = [
        'departments', 'department_org_unit_map', 'roles', 'model_has_permissions', 'model_has_roles',
        'role_has_permissions', 'permission_aliases', 'permission_denies', 'permission_scopes',
        'user_role_grants', 'org_unit_members', 'org_unit_managers', 'personnel_assignments',
        'user_profiles', 'approval_steps', 'workflow_shadow_comparisons', 'menus', 'menu_permissions',
    ];

    /** ตาราง core ใหม่ตาม TASKS R02-2..R02-6 */
    public const CORE_TABLES = [
        // R02-2
        'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'personal_access_tokens', 'notifications',
        // R02-3
        'org_unit_types', 'org_units', 'position_groups', 'positions',
        // R02-4
        'personnel_types', 'personnel', 'personnel_profiles', 'personnel_educations', 'personnel_trainings',
        'personnel_licenses', 'personnel_documents', 'assignments', 'field_classifications',
        'personnel_change_requests', 'sensitive_access_logs',
        // R02-5
        'mfa_credentials', 'login_events',
        // R02-6
        'modules', 'module_features', 'permissions', 'permission_templates', 'template_permissions',
        'position_templates', 'role_grants', 'permission_overrides', 'permission_change_requests',
        'access_reviews', 'access_review_items', 'permission_audit_logs',
    ];

    /** ตารางโมดูลเดิมที่เก็บโครงสร้าง (R02-7) */
    public const MODULE_TABLES = [
        'fiscal_years', 'holidays', 'work_calendar_exceptions', 'work_schedules', 'work_schedule_days', 'work_schedule_assignments',
        'settings', 'setting_audit_logs', 'audit_logs', 'personal_data_access_logs', 'user_delegations',
        'workflow_definitions', 'workflow_versions', 'workflow_steps', 'workflow_transitions', 'workflow_instances',
        'workflow_tasks', 'workflow_actions', 'workflow_delegations',
        'document_templates', 'document_template_versions', 'document_data_sources', 'document_field_definitions',
        'document_field_mappings', 'document_binding_rules', 'document_generated_files', 'document_print_calibrations',
        'document_report_definitions',
        'leave_types', 'leave_policies', 'leaves', 'leave_approvals', 'leave_balances', 'leave_action_logs',
        'leave_adjustments', 'leave_audit_logs', 'leave_document_sequences', 'leave_evidences',
        'attendance_sources', 'devices', 'attendances', 'attendance_raw_events', 'attendance_identity_maps', 'attendance_corrections',
        'ot_orders', 'ot_assignments', 'ot_time_records',
        'saraban_document_types', 'saraban_document_sequences', 'saraban_documents', 'saraban_document_incoming',
        'saraban_document_outgoing', 'saraban_document_attachments', 'saraban_audit_logs', 'saraban_document_receivers',
        'saraban_document_user_states', 'saraban_document_workflow_templates', 'saraban_document_workflow_steps',
        'saraban_document_signatures', 'saraban_document_circulars', 'saraban_document_circular_recipients',
        'notification_types', 'notification_templates', 'notification_preferences', 'notification_events',
        'notification_recipients', 'notification_deliveries',
    ];

    /** คอลัมน์ที่ตัด: [ตาราง, คอลัมน์] */
    public const REMOVED_COLUMNS = [
        ['users', 'custom_permissions'], ['users', 'department_id'], ['users', 'role_id'], ['users', 'name'],
        ['users', 'is_active'], ['users', 'org_unit_id'], ['users', 'personnel_type_id'],
        ['leave_approvals', 'required_role_id'], ['workflow_steps', 'assignee_role_id'],
        ['workflow_tasks', 'assignee_role_id'], ['workflow_delegations', 'scope_role_id'],
        ['saraban_documents', 'department_id'], ['saraban_document_sequences', 'department_id'],
        ['work_calendar_exceptions', 'department_id'], ['personnel', 'user_id'],
    ];

    /** @return array<string, array{string}> */
    public static function removedTables(): array
    {
        return array_combine(self::REMOVED_TABLES, array_map(fn ($t) => [$t], self::REMOVED_TABLES));
    }

    #[DataProvider('removedTables')]
    public function test_removed_table_does_not_exist(string $table): void
    {
        $this->assertFalse(Schema::hasTable($table), "ตาราง {$table} ต้องถูกตัดตาม REBUILD-PLAN-2569 §4.2");
    }

    public function test_all_core_tables_exist(): void
    {
        foreach (self::CORE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "ไม่พบตาราง core {$table}");
        }
    }

    public function test_all_kept_module_tables_exist(): void
    {
        foreach (self::MODULE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "ไม่พบตารางโมดูลเดิม {$table}");
        }
    }

    public function test_removed_columns_do_not_exist(): void
    {
        foreach (self::REMOVED_COLUMNS as [$table, $column]) {
            $this->assertFalse(Schema::hasColumn($table, $column), "คอลัมน์ {$table}.{$column} ต้องถูกตัด");
        }
    }

    public function test_department_references_became_org_unit_references(): void
    {
        foreach (['saraban_documents', 'saraban_document_sequences', 'work_calendar_exceptions'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'org_unit_id'), "{$table} ต้องอ้าง org_units แทน departments");

            $targets = collect(Schema::getForeignKeys($table))
                ->filter(fn (array $fk) => $fk['columns'] === ['org_unit_id'])
                ->pluck('foreign_table')
                ->all();
            $this->assertSame(['org_units'], $targets, "{$table}.org_unit_id ต้องเป็น FK ไป org_units");
        }
    }

    public function test_core_identity_columns_follow_core_03(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'uuid', 'personnel_id', 'username', 'email', 'password', 'status', 'locked_until',
            'separated_at', 'separation_reason', 'last_login_at', 'account_type', 'is_break_glass',
        ]));
    }

    public function test_core_organization_columns_follow_core_01(): void
    {
        $this->assertTrue(Schema::hasColumns('org_unit_types', ['code', 'name', 'level', 'head_title', 'is_active']));
        $this->assertTrue(Schema::hasColumns('org_units', [
            'uuid', 'parent_id', 'org_unit_type_id', 'code', 'name', 'path', 'depth', 'sort_order', 'effective_from', 'effective_to',
        ]));
        $this->assertTrue(Schema::hasColumns('positions', ['kind', 'position_group_id', 'org_unit_type_id']));
    }

    public function test_assignments_are_effective_dated_per_core_02(): void
    {
        $this->assertTrue(Schema::hasColumns('assignments', [
            'uuid', 'personnel_id', 'kind', 'org_unit_id', 'position_id', 'acting_for_assignment_id',
            'order_number', 'order_date', 'effective_from', 'effective_to', 'ended_by', 'end_reason',
        ]));
    }

    public function test_personnel_profiles_have_no_ethnicity_or_religion(): void
    {
        foreach (['ethnicity', 'religion', 'blood_type'] as $column) {
            $this->assertFalse(Schema::hasColumn('personnel_profiles', $column), "ADR-R-007: ห้ามเก็บ {$column}");
        }
        $this->assertTrue(Schema::hasColumn('personnel_profiles', 'national_id_hash'));
    }

    public function test_append_only_logs_have_no_updated_at(): void
    {
        foreach (['sensitive_access_logs', 'login_events', 'permission_audit_logs'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'created_at'));
            $this->assertFalse(Schema::hasColumn($table, 'updated_at'), "{$table} เป็นบันทึกเพิ่มได้อย่างเดียว");
        }
    }

    public function test_role_grants_require_order_number_and_expiry(): void
    {
        $columns = collect(Schema::getColumns('role_grants'))->keyBy('name');

        $this->assertFalse($columns['order_number']['nullable']);
        $this->assertFalse($columns['expires_at']['nullable']);
    }

    public function test_only_baseline_migrations_are_loaded(): void
    {
        $ran = DB::table('migrations')->pluck('migration');
        $files = collect(File::files(database_path('migrations')))->map(fn ($f) => $f->getFilenameWithoutExtension());

        $this->assertEqualsCanonicalizing($files->all(), $ran->all());
        $this->assertTrue($ran->every(fn (string $m) => preg_match('/_r0\d_/', $m) === 1), 'migration ทุกไฟล์ต้องเป็นชุด REBUILD (baseline R02 + เฟสถัดไป)');
        $this->assertFalse(File::exists(database_path('migrations/0001_01_01_000000_create_users_table.php')));
        $this->assertGreaterThan(100, count(File::files(database_path('migrations-legacy'))), 'migration เดิมต้องย้ายไปเก็บที่ migrations-legacy/');
    }
}
