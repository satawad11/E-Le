<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOC-ENGINE-03 — เอกสารฉบับจริงที่ระบบออกให้ + snapshot ข้อมูล ณ เวลานั้น
 *
 * `document_generated_files` เก็บไฟล์ที่ออกไปแล้วพร้อม sha256
 * เอกสารที่ finalize แล้วเป็น **IMMUTABLE** — ห้ามเขียนทับไฟล์เดิม
 * ต้องการแก้ต้องออก revision ใหม่ (revision 1, 2, 3 …) และเก็บของเดิมไว้ทั้งหมด
 *
 * snapshot บน `leaves` เก็บข้อมูลผู้ยื่นและตัวใบลา ณ วันที่ยื่น/อนุมัติ
 * เพื่อให้พิมพ์ย้อนหลังได้ตรงกับตอนนั้น แม้บุคคลจะย้ายหน่วยงานหรือเปลี่ยนตำแหน่งไปแล้ว
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_generated_files', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('module', 30);
            $table->string('document_type', 20)->default('FORM');
            $table->string('source_type', 60);           // Leave, LeaveCancellation, Report...
            $table->string('source_id', 64);
            $table->foreignId('document_template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->foreignId('document_template_version_id')->nullable()->constrained('document_template_versions')->nullOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->string('file_path');
            $table->string('mime_type', 100)->default('application/pdf');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('sha256', 64);
            $table->string('status', 20)->default('GENERATED'); // GENERATED|FINALIZED|SUPERSEDED
            $table->json('snapshot')->nullable();        // ข้อมูลที่ใช้เรนเดอร์ ณ เวลานั้น
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'revision'], 'document_generated_revision_unique');
            $table->index(['module', 'source_type', 'source_id'], 'document_generated_lookup');
        });

        Schema::table('leaves', function (Blueprint $table): void {
            if (! Schema::hasColumn('leaves', 'personnel_snapshot')) {
                $table->json('personnel_snapshot')->nullable()->after('meta_data');
            }
            if (! Schema::hasColumn('leaves', 'document_snapshot')) {
                $table->json('document_snapshot')->nullable()->after('personnel_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table): void {
            foreach (['document_snapshot', 'personnel_snapshot'] as $column) {
                if (Schema::hasColumn('leaves', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('document_generated_files');
    }
};
