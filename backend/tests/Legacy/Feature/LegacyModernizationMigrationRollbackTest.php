<?php

namespace Tests\Legacy\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyModernizationMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_08_to_12_migrations_roll_back_and_reapply_cleanly(): void
    {
        $files = [
            database_path('migrations/2026_08_15_200000_create_attendance_integration_foundation.php'),
            database_path('migrations/2026_08_15_210000_create_overtime_domain.php'),
            database_path('migrations/2026_08_15_220000_create_notification_center.php'),
            database_path('migrations/2026_08_15_230000_register_overtime_canonical_permissions.php'),
        ];
        $migrations = array_map(static fn (string $file) => require $file, $files);

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        foreach (['attendance_sources', 'attendance_raw_events', 'attendance_corrections', 'ot_orders', 'ot_assignments', 'ot_time_records', 'notification_types', 'notification_events', 'notification_deliveries'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} must be removed by rollback");
        }

        foreach ($migrations as $migration) {
            $migration->up();
        }

        foreach (['attendance_sources', 'attendance_raw_events', 'attendance_corrections', 'ot_orders', 'ot_assignments', 'ot_time_records', 'notification_types', 'notification_events', 'notification_deliveries'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} must be restored by reapply");
        }
        $this->assertDatabaseHas('permission_aliases', [
            'legacy_name' => 'attendance.ot',
            'canonical_name' => 'overtime.operation.execute',
        ]);
        $this->assertDatabaseHas('permission_aliases', [
            'legacy_name' => 'attendance.ot_summary',
            'canonical_name' => 'overtime.management.review',
        ]);
    }
}
