<?php

namespace Tests\Feature;

use App\Models\Document\DocumentGeneratedFile;
use App\Models\DocumentTemplate;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\User;
use App\Policies\LeavePolicy;
use App\Services\DocumentEngine\LeaveDocumentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * DC-01 — เอกสารที่ระบบออกให้ต้องเปิดได้เฉพาะผู้ที่เปิดต้นทาง (ใบลา) ได้
 *
 * สิทธิ์ดู/จัดการแม่แบบเป็นสิทธิ์งานออกแบบ ไม่ใช่สิทธิ์อ่านใบลาของคนอื่น
 */
class GeneratedDocumentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $unitA;

    private OrgUnit $unitB;

    private User $ownerA;

    private User $ownerB;

    private DocumentGeneratedFile $fileA;

    private DocumentGeneratedFile $fileB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_uploads');

        $this->unitA = OrgUnit::factory()->create(['name' => 'หน่วย ก']);
        $this->unitB = OrgUnit::factory()->create(['name' => 'หน่วย ข']);
        $this->ownerA = User::factory()->create(['org_unit_id' => $this->unitA->id]);
        $this->ownerB = User::factory()->create(['org_unit_id' => $this->unitB->id]);

        $this->fileA = $this->generatedFor($this->leaveOf($this->ownerA));
        $this->fileB = $this->generatedFor($this->leaveOf($this->ownerB));
    }

    private function leaveOf(User $owner): Leave
    {
        $type = LeaveType::firstOrCreate(['name' => 'ลาป่วย'], ['max_days' => 60]);

        return Leave::create([
            'user_id' => $owner->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-20',
            'total_days' => 1,
            'reason' => 'เหตุผลส่วนตัวของ '.$owner->name,
            'contact_address' => 'ที่อยู่ลับ',
            'status' => 'Approved',
        ]);
    }

    private function generatedFor(Leave $leave): DocumentGeneratedFile
    {
        $template = DocumentTemplate::firstOrCreate(['code' => 'dc01_form'], [
            'name' => 'แบบฟอร์มทดสอบ DC-01', 'module' => 'leave', 'document_type' => 'FORM',
            'paper_size' => 'A4', 'orientation' => 'portrait', 'content_html' => '', 'is_active' => true,
        ]);
        $path = "documents/leave/Leave/{$leave->id}-r1.pdf";
        Storage::disk('private_uploads')->put($path, '%PDF-1.4 test');

        return DocumentGeneratedFile::create([
            'module' => 'LEAVE',
            'document_type' => 'FORM',
            'source_type' => LeaveDocumentGateway::SOURCE_TYPE,
            'source_id' => (string) $leave->id,
            'document_template_id' => $template->id,
            'document_template_version_id' => $template->versions()->value('id'),
            'revision' => 1,
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 13,
            'sha256' => hash('sha256', '%PDF-1.4 test'),
            'status' => DocumentGeneratedFile::STATUS_FINALIZED,
            'snapshot' => ['reason' => $leave->reason, 'contact_address' => $leave->contact_address],
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions, ?OrgUnit $unit = null): User
    {
        $user = User::factory()->create(['org_unit_id' => $unit?->id]);

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * @return list<int>
     */
    private function listedIds(User $user): array
    {
        return collect($this->actingAs($user)->getJson('/api/admin/document-engine/generated')->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
    }

    public function test_template_viewers_without_leave_rights_see_no_documents(): void
    {
        $designer = $this->userWith(['document.template.view', 'document.final.download']);

        $this->assertSame([], $this->listedIds($designer));
        $this->actingAs($designer)->get("/api/admin/document-engine/generated/{$this->fileA->id}/download")->assertForbidden();
    }

    public function test_the_listing_never_returns_the_snapshot_or_the_file_path(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        $response = $this->actingAs($admin)->getJson('/api/admin/document-engine/generated')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertStringNotContainsString('ที่อยู่ลับ', $response->getContent());
        $this->assertStringNotContainsString('เหตุผลส่วนตัว', $response->getContent());
        $this->assertArrayNotHasKey('snapshot', $response->json('data.0'));
        $this->assertArrayNotHasKey('file_path', $response->json('data.0'));
    }

    public function test_department_approvers_see_only_their_own_unit(): void
    {
        $head = $this->userWith(['document.template.view', 'document.final.download', 'leave.approval.dept'], $this->unitA);

        $this->assertSame([$this->fileA->id], $this->listedIds($head));
        $this->actingAs($head)->get("/api/admin/document-engine/generated/{$this->fileA->id}/download")->assertOk();
        $this->actingAs($head)->get("/api/admin/document-engine/generated/{$this->fileB->id}/download")->assertForbidden();
    }

    public function test_organisation_wide_leave_rights_see_everything(): void
    {
        $hr = $this->userWith(['document.template.view', 'document.final.download', 'leave.management.manage'], $this->unitB);

        $this->assertSame([$this->fileA->id, $this->fileB->id], $this->listedIds($hr));
        $this->actingAs($hr)->get("/api/admin/document-engine/generated/{$this->fileA->id}/download")->assertOk();
    }

    public function test_owners_reach_their_own_document(): void
    {
        $this->ownerA->givePermissionTo(Permission::firstOrCreate(['name' => 'document.final.download', 'guard_name' => 'web']));

        $this->actingAs($this->ownerA)->get("/api/admin/document-engine/generated/{$this->fileA->id}/download")->assertOk();
        $this->actingAs($this->ownerA)->get("/api/admin/document-engine/generated/{$this->fileB->id}/download")->assertForbidden();
    }

    public function test_unknown_source_types_are_denied_even_to_broad_leave_rights(): void
    {
        $this->fileB->forceFill(['source_type' => 'SomethingElse'])->saveQuietly();
        $hr = $this->userWith(['document.template.view', 'document.final.download', 'leave.management.manage'], $this->unitA);

        $this->assertSame([$this->fileA->id], $this->listedIds($hr));
        $this->actingAs($hr)->get("/api/admin/document-engine/generated/{$this->fileB->id}/download")->assertForbidden();
    }

    public function test_the_list_query_agrees_with_the_view_policy(): void
    {
        $leaveA = Leave::find($this->fileA->source_id);
        $leaveB = Leave::find($this->fileB->source_id);

        $substitute = User::factory()->create(['org_unit_id' => $this->unitB->id]);
        $leaveA->update(['substitute_id' => $substitute->id]);

        $approver = User::factory()->create(['org_unit_id' => $this->unitB->id]);
        $leaveA->allApprovals()->create(['approver_id' => $approver->id, 'step_order' => 1, 'status' => 'Approved', 'stage' => 'request']);

        $draft = $this->leaveOf($this->ownerB);
        $draft->update(['status' => 'Draft']);

        $viewers = [
            $this->ownerA,
            $this->ownerB,
            $substitute,
            $approver,
            $this->userWith(['leave.approval.dept'], $this->unitA),
            $this->userWith(['leave.report.view']),
            $this->userWith([]),
        ];

        $policy = app(LeavePolicy::class);

        foreach ($viewers as $viewer) {
            $fromQuery = $policy->viewableQuery(Leave::query(), $viewer)->pluck('id')->sort()->values()->all();
            $fromPolicy = collect([$leaveA, $leaveB, $draft])
                ->filter(fn (Leave $leave) => $policy->view($viewer, $leave->fresh()))
                ->pluck('id')->sort()->values()->all();

            $this->assertSame($fromPolicy, $fromQuery, "viewer {$viewer->id}");
        }
    }

    public function test_a_denied_download_is_audited(): void
    {
        $designer = $this->userWith(['document.final.download']);

        $this->actingAs($designer)->get("/api/admin/document-engine/generated/{$this->fileA->id}/download")->assertForbidden();

        $this->assertDatabaseHas('audit_logs', ['action' => 'document_download_denied', 'resource_id' => (string) $this->fileA->id]);
    }
}
