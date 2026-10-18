<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOC-ENGINE-01 — ขยายตารางแม่แบบเดิมให้รองรับ Document Engine
 *
 * เพิ่มอย่างเดียว ไม่แตะคอลัมน์เดิมแม้แต่ตัวเดียว:
 *   - `render_mode` แยกแม่แบบ HTML เดิม (`html`) ออกจากแม่แบบที่วางฟิลด์บน PDF (`pdf_overlay`)
 *     ค่าเริ่มต้นเป็น `html` ทุกแถว แม่แบบเดิมจึงพิมพ์ออกมาเหมือนเดิมทุกประการ
 *   - `status` ของเวอร์ชัน (DRAFT/ACTIVE/RETIRED/ARCHIVED) โดย backfill จาก `is_active`
 *     และ **คง `is_active` ไว้ทั้งคอลัมน์และการใช้งาน** เพื่อไม่ให้โค้ด/เทสต์เดิมพัง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table): void {
            if (! Schema::hasColumn('document_templates', 'document_type')) {
                $table->string('document_type', 20)->default('FORM')->after('module');
            }
            if (! Schema::hasColumn('document_templates', 'render_mode')) {
                $table->string('render_mode', 20)->default('html')->after('document_type');
            }
            if (! Schema::hasColumn('document_templates', 'description')) {
                $table->string('description')->nullable()->after('render_mode');
            }
            if (! Schema::hasColumn('document_templates', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('document_template_versions', function (Blueprint $table): void {
            if (! Schema::hasColumn('document_template_versions', 'status')) {
                $table->string('status', 20)->default('ACTIVE')->after('version');
            }
            if (! Schema::hasColumn('document_template_versions', 'render_mode')) {
                $table->string('render_mode', 20)->default('html')->after('status');
            }
            if (! Schema::hasColumn('document_template_versions', 'pdf_file_path')) {
                $table->string('pdf_file_path')->nullable()->after('render_mode');
            }
            if (! Schema::hasColumn('document_template_versions', 'page_count')) {
                $table->unsignedSmallInteger('page_count')->nullable()->after('pdf_file_path');
            }
            if (! Schema::hasColumn('document_template_versions', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('document_template_versions', 'published_by')) {
                $table->foreignId('published_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('document_template_versions', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('published_by');
            }
        });

        // เวอร์ชันที่ใช้งานอยู่ = ACTIVE, ที่ปิดไปแล้ว = RETIRED (ห้ามลบทิ้งตามกฎเอกสารย้อนหลัง)
        DB::table('document_template_versions')->where('is_active', true)->update(['status' => 'ACTIVE']);
        DB::table('document_template_versions')->where('is_active', false)->update(['status' => 'RETIRED']);
    }

    public function down(): void
    {
        Schema::table('document_template_versions', function (Blueprint $table): void {
            foreach (['published_at'] as $column) {
                if (Schema::hasColumn('document_template_versions', $column)) {
                    $table->dropColumn($column);
                }
            }
            if (Schema::hasColumn('document_template_versions', 'published_by')) {
                $table->dropConstrainedForeignId('published_by');
            }
            if (Schema::hasColumn('document_template_versions', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
            foreach (['page_count', 'pdf_file_path', 'render_mode', 'status'] as $column) {
                if (Schema::hasColumn('document_template_versions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('document_templates', function (Blueprint $table): void {
            if (Schema::hasColumn('document_templates', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
            foreach (['description', 'render_mode', 'document_type'] as $column) {
                if (Schema::hasColumn('document_templates', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
