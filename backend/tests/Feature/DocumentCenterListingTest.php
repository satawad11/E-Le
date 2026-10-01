<?php

namespace Tests\Feature;

use App\Models\Document\DocumentGeneratedFile;
use App\Models\DocumentTemplate;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Setting;
use App\Models\User;
use App\Services\DocumentEngine\LeaveDocumentGateway;
use App\Services\LeaveDocumentTemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * DC-04 / DC-06 — รายการเอกสารแบ่งหน้าและกรองได้จริง และพรีวิวใช้สไตล์หน้ากระดาษเดียวกับตอนพิมพ์
 */
class DocumentCenterListingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['name' => 'ผู้ดูแล']);
        $this->admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    }

    private function documents(int $count, string $status = DocumentGeneratedFile::STATUS_FINALIZED, string $at = '2026-03-10 10:00:00'): void
    {
        $template = DocumentTemplate::firstOrCreate(['code' => 'listing_form'], [
            'name' => 'แบบลาพักผ่อน', 'module' => 'leave', 'document_type' => 'FORM',
            'paper_size' => 'A4', 'orientation' => 'portrait', 'content_html' => '', 'is_active' => true,
        ]);
        $type = LeaveType::firstOrCreate(['name' => 'ลาพักผ่อน'], ['max_days' => 10]);
        $owner = User::factory()->create(['name' => 'สมศรี ทดสอบ']);

        for ($i = 0; $i < $count; $i++) {
            $leave = Leave::create([
                'user_id' => $owner->id, 'leave_type_id' => $type->id,
                'start_date' => '2026-03-20', 'end_date' => '2026-03-20', 'total_days' => 1,
                'reason' => 'ทดสอบ', 'status' => 'Approved',
            ]);
            $leave->forceFill(['document_number' => 'ลพ.'.str_pad((string) $leave->id, 4, '0', STR_PAD_LEFT)])->saveQuietly();

            DocumentGeneratedFile::create([
                'module' => 'LEAVE', 'document_type' => 'FORM',
                'source_type' => LeaveDocumentGateway::SOURCE_TYPE, 'source_id' => (string) $leave->id,
                'document_template_id' => $template->id,
                'document_template_version_id' => $template->versions()->value('id'),
                'revision' => 1, 'file_path' => "documents/{$leave->id}.pdf", 'mime_type' => 'application/pdf',
                'file_size' => 1, 'sha256' => str_repeat('0', 64), 'status' => $status, 'generated_at' => $at,
            ]);
        }
    }

    public function test_documents_beyond_the_first_page_are_reachable(): void
    {
        $this->documents(31);

        $first = $this->actingAs($this->admin)->getJson('/api/admin/document-engine/generated')->assertOk();
        $second = $this->actingAs($this->admin)->getJson('/api/admin/document-engine/generated?page=2')->assertOk();

        $this->assertSame(31, $first->json('total'));
        $this->assertSame(2, $first->json('last_page'));
        $this->assertCount(30, $first->json('data'));
        $this->assertCount(1, $second->json('data'));
    }

    public function test_filters_by_status_date_and_text(): void
    {
        $this->documents(2, DocumentGeneratedFile::STATUS_FINALIZED, '2026-03-10 10:00:00');
        $this->documents(1, DocumentGeneratedFile::STATUS_SUPERSEDED, '2026-02-01 10:00:00');

        $this->assertSame(2, $this->actingAs($this->admin)->getJson('/api/admin/document-engine/generated?status=FINALIZED')->json('total'));
        $this->assertSame(1, $this->actingAs($this->admin)->getJson('/api/admin/document-engine/generated?date_to=2026-02-28')->json('total'));
        $this->assertSame(3, $this->actingAs($this->admin)->getJson('/api/admin/document-engine/generated?q='.urlencode('ลาพักผ่อน'))->json('total'));

        $number = Leave::orderBy('id')->value('document_number');
        $this->assertSame(1, $this->actingAs($this->admin)->getJson('/api/admin/document-engine/generated?q='.urlencode($number))->json('total'));

        $this->actingAs($this->admin)->getJson('/api/admin/document-engine/generated?status=WHATEVER')->assertUnprocessable();
    }

    public function test_rows_carry_a_readable_label_instead_of_the_internal_id(): void
    {
        $this->documents(1);

        $label = $this->actingAs($this->admin)->getJson('/api/admin/document-engine/generated')->json('data.0.source_label');

        $this->assertStringContainsString('ใบลา ลพ.', $label);
        $this->assertStringContainsString('สมศรี ทดสอบ', $label);
    }

    public function test_preview_page_style_uses_the_margins_being_edited_and_falls_back_to_system_values(): void
    {
        Setting::create(['key' => 'margin_left', 'value' => '3', 'type' => 'string']);
        $resolver = app(LeaveDocumentTemplateResolver::class);

        $this->assertStringContainsString('@page{margin:25mm 15mm 20mm 30mm;}', $resolver->previewPageStyle(['top' => 25, 'right' => 15, 'bottom' => 20]));
        $this->assertStringContainsString('@page{margin:8mm 12mm 8mm 30mm;}', $resolver->previewPageStyle([]));
    }

    public function test_preview_endpoint_accepts_margins(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/admin/document-engine/templates/preview', [
                'content_html' => '<p>{{ $user_name }}</p>',
                'margin_top' => 25, 'margin_right' => 15, 'margin_bottom' => 20, 'margin_left' => 30,
            ])
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
