<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R02-7 — Document engine (แม่แบบเอกสาร ฟิลด์ การผูกแบบฟอร์ม ไฟล์ที่สร้าง)
 *
 * สร้างในรูปสุดท้ายของระบบเดิม (รวม alter ทุกไฟล์ใน database/migrations-legacy/)
 * ตามแผน REBUILD-2569 §4.2 "เก็บโครงสร้าง ล้างข้อมูล" · ตัดคอลัมน์/FK ที่อ้างตารางที่ถูกตัด
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 255);
            $table->string('name', 255);
            $table->string('module', 255)->default('leave');
            $table->string('document_type', 20)->default('FORM');
            $table->string('render_mode', 20)->default('html');
            $table->string('description', 255)->nullable();
            $table->string('paper_size', 255)->default('A4');
            $table->string('orientation', 255)->default('portrait');
            $table->decimal('margin_top', 4, 1)->default(8.0);
            $table->decimal('margin_bottom', 4, 1)->default(8.0);
            $table->decimal('margin_left', 4, 1)->default(12.0);
            $table->decimal('margin_right', 4, 1)->default(12.0);
            $table->longText('content_html');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['code'], 'document_templates_code_unique');
            $table->foreign(['created_by'], 'document_templates_created_by_foreign')->references(['id'])->on('users')->nullOnDelete();
        });

        Schema::create('document_template_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_template_id');
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('ACTIVE');
            $table->string('render_mode', 20)->default('html');
            $table->string('pdf_file_path', 255)->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();
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
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['document_template_id', 'effective_from', 'effective_to'], 'document_template_effective');
            $table->unique(['document_template_id', 'version'], 'document_template_version_unique');
            $table->foreign(['created_by'], 'document_template_versions_created_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['document_template_id'], 'document_template_versions_document_template_id_foreign')->references(['id'])->on('document_templates')->restrictOnDelete();
            $table->foreign(['published_by'], 'document_template_versions_published_by_foreign')->references(['id'])->on('users')->nullOnDelete();
        });

        Schema::create('document_data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120);
            $table->string('name_th', 255);
            $table->string('module', 30)->default('leave');
            $table->string('kind', 20)->default('RECORD');
            $table->string('provider_class', 255);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['code'], 'document_data_sources_code_unique');
        });

        Schema::create('document_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120);
            $table->string('label_th', 255);
            $table->string('group_th', 120)->nullable();
            $table->string('data_type', 20)->default('TEXT');
            $table->string('source', 120)->nullable();
            $table->string('module', 30)->default('leave');
            $table->string('format', 255)->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['code'], 'document_field_definitions_code_unique');
            $table->index(['module', 'group_th'], 'document_field_definitions_module_group_th_index');
        });

        Schema::create('document_field_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_template_version_id');
            $table->string('field_code', 120);
            $table->string('field_type', 20)->default('TEXT');
            $table->unsignedSmallInteger('page')->default(1);
            $table->decimal('x', 8, 2);
            $table->decimal('y', 8, 2);
            $table->decimal('width', 8, 2)->default(150.00);
            $table->decimal('height', 8, 2)->default(18.00);
            $table->string('font', 60)->nullable();
            $table->decimal('font_size', 5, 2)->default(14.00);
            $table->string('align', 10)->default('left');
            $table->string('overflow', 20)->default('shrink');
            $table->string('format', 255)->nullable();
            $table->string('static_text', 255)->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['document_template_version_id', 'page'], 'document_field_mapping_page');
            $table->foreign(['document_template_version_id'], 'document_field_mappings_document_template_version_id_foreign')->references(['id'])->on('document_template_versions')->cascadeOnDelete();
        });

        Schema::create('document_binding_rules', function (Blueprint $table) {
            $table->id();
            $table->string('module', 30);
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('document_template_id');
            $table->unsignedInteger('priority')->default(100);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->json('conditions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['module', 'entity_type', 'entity_id', 'priority'], 'document_binding_lookup');
            $table->foreign(['created_by'], 'document_binding_rules_created_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['document_template_id'], 'document_binding_rules_document_template_id_foreign')->references(['id'])->on('document_templates')->cascadeOnDelete();
        });

        Schema::create('document_generated_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->string('module', 30);
            $table->string('document_type', 20)->default('FORM');
            $table->string('source_type', 60);
            $table->string('source_id', 64);
            $table->unsignedBigInteger('document_template_id')->nullable();
            $table->unsignedBigInteger('document_template_version_id')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->string('file_path', 255);
            $table->string('mime_type', 100)->default('application/pdf');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('sha256', 64);
            $table->string('status', 20)->default('GENERATED');
            $table->json('snapshot')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['uuid'], 'document_generated_files_uuid_unique');
            $table->index(['module', 'source_type', 'source_id'], 'document_generated_lookup');
            $table->unique(['source_type', 'source_id', 'revision'], 'document_generated_revision_unique');
            $table->foreign(['document_template_id'], 'document_generated_files_document_template_id_foreign')->references(['id'])->on('document_templates')->nullOnDelete();
            $table->foreign(['document_template_version_id'], 'document_generated_files_document_template_version_id_foreign')->references(['id'])->on('document_template_versions')->nullOnDelete();
            $table->foreign(['generated_by'], 'document_generated_files_generated_by_foreign')->references(['id'])->on('users')->nullOnDelete();
        });

        Schema::create('document_print_calibrations', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20)->default('GLOBAL');
            $table->unsignedBigInteger('document_template_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('printer_profile', 120)->nullable();
            $table->decimal('x_offset', 6, 2)->default(0.00);
            $table->decimal('y_offset', 6, 2)->default(0.00);
            $table->decimal('scale', 5, 3)->default(1.000);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['scope', 'document_template_id', 'user_id'], 'document_calibration_lookup');
            $table->foreign(['document_template_id'], 'document_print_calibrations_document_template_id_foreign')->references(['id'])->on('document_templates')->cascadeOnDelete();
            $table->foreign(['user_id'], 'document_print_calibrations_user_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
        });

        Schema::create('document_report_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120);
            $table->string('name_th', 255);
            $table->string('module', 30)->default('leave');
            $table->string('data_source_code', 120);
            $table->json('filters')->nullable();
            $table->json('columns')->nullable();
            $table->json('grouping')->nullable();
            $table->json('aggregates')->nullable();
            $table->json('layout')->nullable();
            $table->string('orientation', 20)->default('portrait');
            $table->string('paper_size', 20)->default('A4');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['code'], 'document_report_definitions_code_unique');
            $table->index(['module', 'is_active'], 'document_report_definitions_module_is_active_index');
            $table->foreign(['created_by'], 'document_report_definitions_created_by_foreign')->references(['id'])->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_report_definitions');
        Schema::dropIfExists('document_print_calibrations');
        Schema::dropIfExists('document_generated_files');
        Schema::dropIfExists('document_binding_rules');
        Schema::dropIfExists('document_field_mappings');
        Schema::dropIfExists('document_field_definitions');
        Schema::dropIfExists('document_data_sources');
        Schema::dropIfExists('document_template_versions');
        Schema::dropIfExists('document_templates');
    }
};
