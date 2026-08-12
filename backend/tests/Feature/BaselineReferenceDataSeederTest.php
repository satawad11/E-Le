<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\Holiday;
use Database\Seeders\DocumentTemplateSeeder;
use Database\Seeders\ThaiGovernmentHoliday2569Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BaselineReferenceDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_2569_government_holiday_baseline_idempotently(): void
    {
        $this->seed(ThaiGovernmentHoliday2569Seeder::class);
        $this->seed(ThaiGovernmentHoliday2569Seeder::class);

        $this->assertSame(20, Holiday::query()->count());
        $this->assertDatabaseHas('holidays', [
            'date' => '2026-05-13',
            'name' => 'วันพืชมงคล',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('holidays', [
            'date' => '2026-07-30',
            'name' => 'วันเข้าพรรษา',
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('holidays', ['date' => '2026-10-16']);
    }

    public function test_it_registers_all_five_operational_leave_templates_without_overwriting_customizations(): void
    {
        $this->seed(DocumentTemplateSeeder::class);

        $codes = [
            'leave_sick_personal',
            'leave_vacation',
            'leave_paternity',
            'leave_ordination',
            'leave_cancel',
        ];

        $this->assertSame(5, DocumentTemplate::query()->whereIn('code', $codes)->count());
        $this->assertTrue(DocumentTemplate::query()->whereIn('code', $codes)->get()->every(
            fn (DocumentTemplate $template) => $template->is_active
                && $template->paper_size === 'A4'
                && str_contains($template->content_html, '<!DOCTYPE html>')
        ));

        DocumentTemplate::query()->where('code', 'leave_vacation')->update(['content_html' => '<p>custom</p>']);
        $this->seed(DocumentTemplateSeeder::class);

        $this->assertSame(7, DocumentTemplate::query()->count());
        $this->assertSame('<p>custom</p>', DocumentTemplate::query()->where('code', 'leave_vacation')->value('content_html'));
    }
}
