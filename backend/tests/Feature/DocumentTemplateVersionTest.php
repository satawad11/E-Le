<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentTemplateVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_version_foundation_schema_exists(): void
    {
        $this->assertTrue(Schema::hasColumns('document_template_versions', ['document_template_id', 'version', 'content_html', 'effective_from', 'effective_to', 'is_active']));
        $this->assertTrue(Schema::hasColumn('leaves', 'document_template_version_id'));
    }

    public function test_creating_and_updating_template_preserves_versions(): void
    {
        $template = DocumentTemplate::create(['code' => 'versioned', 'name' => 'แบบทดสอบ', 'module' => 'leave', 'content_html' => '<p>v1</p>']);
        $template->update(['content_html' => '<p>v2</p>']);

        $versions = DocumentTemplateVersion::where('document_template_id', $template->id)->orderBy('version')->get();
        $this->assertSame([1, 2], $versions->pluck('version')->all());
        $this->assertSame('<p>v1</p>', $versions[0]->content_html);
        $this->assertNotNull($versions[0]->effective_to);
        $this->assertSame('<p>v2</p>', $versions[1]->content_html);
    }

    public function test_new_leave_pins_an_active_leave_template_version(): void
    {
        $template = DocumentTemplate::create(['code' => 'leave_default', 'name' => 'แบบลา', 'module' => 'leave', 'content_html' => '<p>leave</p>']);
        $user = User::factory()->create();
        $type = LeaveType::create(['name' => 'ลาทดสอบ template', 'max_days' => 1]);
        $leave = Leave::create(['user_id' => $user->id, 'leave_type_id' => $type->id, 'start_date' => today(), 'end_date' => today(), 'total_days' => 1, 'reason' => 'test']);

        $this->assertSame($template->currentVersion->id, $leave->document_template_version_id);
    }
}
