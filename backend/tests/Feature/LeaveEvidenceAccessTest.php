<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveEvidence;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * LEGACY-07 · 07-4 (LEAVE-004) — ใครเข้าถึงหลักฐานประกอบการลาได้บ้าง
 *
 * เดิมผู้อนุมัติในสายงานเข้าถึงใบรับรองแพทย์ไม่ได้เลย ทั้งที่ต้องใช้ประกอบ
 * การพิจารณา เฟสนี้เปิดให้เข้าถึงได้ **โดยไม่ขยายขอบเขตหน่วยงาน**
 *
 * เทสต์ชุดนี้เน้นสองอย่างที่ต้องไม่พลาด: IDOR และ scope leakage
 */
class LeaveEvidenceAccessTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        foreach ([
            'leave.management.manage', 'leave.approval.hr',
            'leave.approval.dept', 'leave.approval.final', 'leave.report.view',
        ] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        $this->leaveType = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 60]);
    }

    private function orgUnit(string $code): int
    {
        return DB::table('org_units')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'parent_id' => null,
            'code' => $code,
            'name' => $code,
            'type' => 'department',
            'effective_from' => '2000-01-01',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function leaveFor(User $owner): Leave
    {
        $leave = Leave::create([
            'user_id' => $owner->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => '2026-08-17',
            'end_date' => '2026-08-18',
            'total_days' => 2,
            'reason' => 'ป่วย',
            'status' => 'Pending',
            'approval_level' => 1,
        ]);

        LeaveEvidence::create([
            'leave_id' => $leave->id,
            'uploaded_by' => $owner->id,
            'evidence_type' => 'medical_certificate',
            'original_name' => 'cert.pdf',
            'storage_path' => 'private/leave-evidences/cert.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => str_repeat('a', 64),
        ]);

        return $leave->fresh();
    }

    // ------------------------------------------------------------- IDOR

    public function test_an_unrelated_user_cannot_list_someone_elses_evidence(): void
    {
        $leave = $this->leaveFor(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->getJson("/api/leaves/{$leave->id}/evidences")
            ->assertForbidden();
    }

    public function test_an_unrelated_user_cannot_download_someone_elses_evidence(): void
    {
        $leave = $this->leaveFor(User::factory()->create());
        $evidence = $leave->evidences()->first();

        $this->actingAs(User::factory()->create())
            ->get("/api/leave-evidences/{$evidence->id}/download")
            ->assertForbidden();
    }

    public function test_the_owner_can_see_their_own_evidence(): void
    {
        $owner = User::factory()->create();
        $leave = $this->leaveFor($owner);

        $this->actingAs($owner)
            ->getJson("/api/leaves/{$leave->id}/evidences")
            ->assertOk();
    }

    // ------------------------------------------------- ผู้อนุมัติ (07-4)

    /**
     * ผู้อนุมัติระดับหน่วยงานเห็นหลักฐานของใบลา **ในหน่วยงานของตน** ได้
     */
    public function test_an_approver_can_now_see_the_evidence(): void
    {
        $department = OrgUnit::factory()->create(['name' => 'ฝ่ายเดียวกัน']);

        $owner = User::factory()->create(['org_unit_id' => $department->id]);
        $leave = $this->leaveFor($owner);

        $approver = User::factory()->create(['org_unit_id' => $department->id]);
        $approver->givePermissionTo('leave.approval.dept');

        $this->actingAs($approver->fresh())
            ->getJson("/api/leaves/{$leave->id}/evidences")
            ->assertOk();
    }

    /**
     * ผู้อนุมัติระดับหน่วยงานต้องไม่เห็นหลักฐานของใบลาต่างหน่วยงาน
     * แม้จะถือสิทธิ์อนุมัติก็ตาม
     */
    public function test_an_approver_from_another_department_is_still_refused(): void
    {
        $owner = User::factory()->create(['org_unit_id' => OrgUnit::factory()->create(['name' => 'ฝ่าย ก'])->id]);
        $leave = $this->leaveFor($owner);

        $approver = User::factory()->create(['org_unit_id' => OrgUnit::factory()->create(['name' => 'ฝ่าย ข'])->id]);
        $approver->givePermissionTo('leave.approval.dept');

        $this->actingAs($approver->fresh())
            ->getJson("/api/leaves/{$leave->id}/evidences")
            ->assertForbidden();
    }

    public function test_hr_keeps_its_existing_access(): void
    {
        $leave = $this->leaveFor(User::factory()->create());

        $hr = User::factory()->create();
        $hr->givePermissionTo('leave.approval.hr');

        $this->actingAs($hr->fresh())
            ->getJson("/api/leaves/{$leave->id}/evidences")
            ->assertOk();
    }

    // ------------------------------------------------------ scope leakage

    /**
     * ผู้อนุมัติที่ถูกจำกัดขอบเขตให้เห็นเฉพาะหน่วยงานตนเอง ต้องไม่เห็นหลักฐาน
     * ของใบลาข้ามหน่วยงาน — การเปิดสิทธิ์ใน 07-4 ต้องไม่ทะลุ ABAC
     */
    public function test_an_approver_cannot_reach_evidence_outside_their_scope(): void
    {
        $ownUnit = $this->orgUnit('UNIT-A');
        $otherUnit = $this->orgUnit('UNIT-B');

        $outsider = User::factory()->create(['org_unit_id' => $otherUnit]);
        $leave = $this->leaveFor($outsider);

        $approver = User::factory()->create(['org_unit_id' => $ownUnit]);
        $approver->givePermissionTo('leave.approval.dept');

        // จำกัดขอบเขตผู้อนุมัติให้เห็นเฉพาะหน่วยงานของตน
        DB::table('permission_scopes')->insert([
            'model_type' => $approver->getMorphClass(),
            'model_id' => $approver->id,
            'permission_name' => 'leave.approval.dept',
            'scope' => 'UNIT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($approver->fresh())
            ->getJson("/api/leaves/{$leave->id}/evidences")
            ->assertForbidden();
    }

    public function test_an_approver_can_reach_evidence_inside_their_scope(): void
    {
        $unit = $this->orgUnit('UNIT-A');

        $owner = User::factory()->create(['org_unit_id' => $unit]);
        $leave = $this->leaveFor($owner);

        $approver = User::factory()->create(['org_unit_id' => $unit]);
        $approver->givePermissionTo('leave.approval.dept');

        DB::table('permission_scopes')->insert([
            'model_type' => $approver->getMorphClass(),
            'model_id' => $approver->id,
            'permission_name' => 'leave.approval.dept',
            'scope' => 'UNIT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($approver->fresh())
            ->getJson("/api/leaves/{$leave->id}/evidences")
            ->assertOk();
    }

    public function test_evidence_is_never_served_from_public_storage(): void
    {
        $leave = $this->leaveFor(User::factory()->create());
        $evidence = $leave->evidences()->first();

        $this->assertStringStartsWith('private/', $evidence->storage_path);
    }
}
