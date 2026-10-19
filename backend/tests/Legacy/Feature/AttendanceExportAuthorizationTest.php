<?php

namespace Tests\Legacy\Feature;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * SEC-009 — Export ข้อมูลลงเวลาทั้งองค์กร
 *
 * เดิม 7 endpoint นี้อยู่ **นอก** กลุ่ม auth:sanctum และรับ Sanctum token
 * ทาง query string · controller ตรวจเพียงว่า token ใช้ได้ แต่ไม่ตรวจ
 * permission ใด ๆ ผู้ใช้ที่ล็อกอินคนใดก็ได้จึง export ข้อมูลลงเวลาของทั้ง
 * องค์กรออกไปได้ และ token ยังไปโผล่ใน access log / browser history
 *
 * เทสต์ชุดนี้เขียนก่อนแก้โค้ด
 */
class AttendanceExportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** endpoint ทั้งหมดที่อยู่ในขอบเขต SEC-009 */
    public static function exportEndpoints(): array
    {
        return [
            'summary preview' => ['/api/admin/attendances/summary/preview'],
            'summary pdf' => ['/api/admin/attendances/summary/export/pdf'],
            'summary word' => ['/api/admin/attendances/summary/export/word'],
            'ot preview' => ['/api/admin/attendances/ot-summary/preview'],
            'ot pdf' => ['/api/admin/attendances/ot-summary/export/pdf'],
            'ot word' => ['/api/admin/attendances/ot-summary/export/word'],
            'ot excel' => ['/api/admin/attendances/ot-summary/export/excel'],
        ];
    }

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['attendance.view_all', 'attendance.ot_summary'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->department = Department::create(['name' => 'ฝ่ายทดสอบ']);

        $staff = User::factory()->create(['department_id' => $this->department->id]);
        Attendance::create([
            'user_id' => $staff->id,
            'date' => now()->toDateString(),
            'check_in_time' => now()->setTime(8, 30),
            'status' => 'Present',
        ]);
    }

    private function exportQuery(): string
    {
        return http_build_query(['month' => now()->month, 'year' => now()->year]);
    }

    #[DataProvider('exportEndpoints')]
    public function test_unauthenticated_request_is_rejected(string $endpoint): void
    {
        $this->getJson($endpoint.'?'.$this->exportQuery())->assertUnauthorized();
    }

    #[DataProvider('exportEndpoints')]
    public function test_authenticated_user_without_permission_is_forbidden(string $endpoint): void
    {
        $user = User::factory()->create(['department_id' => $this->department->id]);

        $this->actingAs($user)
            ->getJson($endpoint.'?'.$this->exportQuery())
            ->assertForbidden();
    }

    #[DataProvider('exportEndpoints')]
    public function test_user_with_permission_is_allowed(string $endpoint): void
    {
        $user = User::factory()->create(['department_id' => $this->department->id]);
        $user->givePermissionTo('attendance.view_all');
        $user->givePermissionTo('attendance.ot_summary');

        $this->actingAs($user->fresh())
            ->get($endpoint.'?'.$this->exportQuery())
            ->assertOk();
    }

    /**
     * ต้องเลิกยอมรับ token ทาง query string — เดิมใช้กลไกนี้เป็นการยืนยันตัวตน
     * ซึ่งทำให้ token รั่วผ่าน access log และ referrer
     */
    #[DataProvider('exportEndpoints')]
    public function test_token_in_query_string_is_no_longer_accepted(string $endpoint): void
    {
        $user = User::factory()->create(['department_id' => $this->department->id]);
        $user->givePermissionTo('attendance.view_all');
        $token = $user->createToken('export-test')->plainTextToken;

        $this->getJson($endpoint.'?'.$this->exportQuery().'&token='.$token)
            ->assertUnauthorized();
    }
}
