<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_template_id')->constrained('document_templates')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('paper_size', 20);
            $table->string('orientation', 20);
            $table->decimal('margin_top', 4, 1);
            $table->decimal('margin_bottom', 4, 1);
            $table->decimal('margin_left', 4, 1);
            $table->decimal('margin_right', 4, 1);
            $table->longText('content_html');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['document_template_id', 'version'], 'document_template_version_unique');
            $table->index(['document_template_id', 'effective_from', 'effective_to'], 'document_template_effective');
        });

        Schema::table('leaves', function (Blueprint $table) {
            $table->foreignId('document_template_version_id')->nullable()->after('fiscal_year_id')
                ->constrained('document_template_versions')->nullOnDelete();
        });

        DB::table('document_templates')->orderBy('id')->each(function (object $template): void {
            DB::table('document_template_versions')->insert([
                'document_template_id' => $template->id, 'version' => 1,
                'paper_size' => $template->paper_size, 'orientation' => $template->orientation,
                'margin_top' => $template->margin_top, 'margin_bottom' => $template->margin_bottom,
                'margin_left' => $template->margin_left, 'margin_right' => $template->margin_right,
                'content_html' => $template->content_html,
                'effective_from' => $template->created_at ? substr((string) $template->created_at, 0, 10) : now()->toDateString(),
                'effective_to' => null, 'is_active' => $template->is_active,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $defaultVersion = DB::table('document_template_versions')
            ->join('document_templates', 'document_templates.id', '=', 'document_template_versions.document_template_id')
            ->where('document_templates.module', 'leave')->where('document_template_versions.is_active', true)
            ->orderBy('document_templates.id')->value('document_template_versions.id');
        if ($defaultVersion) {
            DB::table('leaves')->whereNull('document_template_version_id')->update(['document_template_version_id' => $defaultVersion]);
        }
    }

    public function down(): void
    {
        Schema::table('leaves', fn (Blueprint $table) => $table->dropConstrainedForeignId('document_template_version_id'));
        Schema::dropIfExists('document_template_versions');
    }
};
