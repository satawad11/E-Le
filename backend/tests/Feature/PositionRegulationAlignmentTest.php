<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REDESIGN-2569 เฟส 4.4 · D-04 — ทะเบียนตำแหน่งตามระเบียบบริหารสถานศึกษา พ.ศ. 2569 ข้อ 13 และ 40
 */
class PositionRegulationAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private function active(string $code): ?bool
    {
        $value = DB::table('positions')->where('code', $code)->value('is_active');

        return $value === null ? null : (bool) $value;
    }

    public function test_one_deputy_director_position_replaces_the_four_division_specific_ones(): void
    {
        $this->assertTrue($this->active('DEPUTY_DIRECTOR'));
        $this->assertSame('รองผู้อำนวยการ', DB::table('positions')->where('code', 'DEPUTY_DIRECTOR')->value('name'));

        foreach (['DEPUTY_RESOURCES', 'DEPUTY_STRATEGY', 'DEPUTY_STUDENT_AFFAIRS', 'DEPUTY_ACADEMIC'] as $code) {
            $this->assertFalse($this->active($code), "{$code} ต้องปิดใช้ ไม่ลบ");
        }
    }

    public function test_employment_statuses_are_no_longer_positions(): void
    {
        $this->assertTrue($this->active('TEACHER_INSTRUCTOR'));
        foreach (['TEACHER_GOV_EMPLOYEE', 'CONTRACT_TEACHER', 'HOURLY_TEACHER'] as $code) {
            $this->assertFalse($this->active($code));
        }
        $this->assertTrue($this->active('TEACHER'), 'ตำแหน่งครูตามข้อ 40 (1) ยังใช้ได้');
        $this->assertTrue($this->active('STAFF_FINANCE'), 'ตำแหน่งเจ้าหน้าที่ประจำงานคงไว้จนกว่าจะนำเข้าหน้าที่ประจำงาน');
    }

    public function test_down_restores_the_previous_catalog_without_losing_assigned_rows(): void
    {
        $migration = require database_path('migrations/2026_10_19_140000_align_positions_with_school_regulation.php');

        $migration->down();
        $this->assertTrue($this->active('DEPUTY_ACADEMIC'));
        $this->assertNull($this->active('DEPUTY_DIRECTOR'));

        $migration->up();
        $migration->up();
        $this->assertSame(1, DB::table('positions')->where('code', 'DEPUTY_DIRECTOR')->count(), 'รันซ้ำต้องไม่สร้างซ้ำ');
    }
}
