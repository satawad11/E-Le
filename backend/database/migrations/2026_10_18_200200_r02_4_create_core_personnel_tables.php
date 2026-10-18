<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R02-4 — CORE-02 Personnel (docs/modules/CORE-02-PERSONNEL.md)
 *
 * - บุคลากร ≠ บัญชีผู้ใช้: `personnel` ไม่มี user_id บัญชีชี้มาที่บุคลากร (`users.personnel_id`, R02-5)
 * - ฟิลด์ชั้น `sensitive` ของ `personnel_profiles` เก็บเป็น text เพื่อรองรับค่าเข้ารหัส (cast `encrypted`)
 *   ค้นหาเลขประจำตัวประชาชนผ่าน `national_id_hash` เท่านั้น
 * - ไม่มีคอลัมน์เชื้อชาติ ศาสนา หมู่เลือด (ADR-R-007)
 * - `assignments` มีวันที่มีผล ห้ามเขียนทับ: แก้โดยปิดแถวเดิม (`effective_to`) แล้วเพิ่มแถวใหม่
 *   กฎ primary ซ้อน / management ซ้อนตรวจใน AssignmentService (R-03)
 * - `sensitive_access_logs` เพิ่มได้อย่างเดียว (ไม่มี updated_at)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('has_license')->default(false);
            $table->boolean('has_contract_end')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('personnel', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('personnel_code', 40)->unique();
            $table->string('title', 50)->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('first_name_en', 100)->nullable();
            $table->string('last_name_en', 100)->nullable();
            $table->foreignId('personnel_type_id')->constrained('personnel_types')->restrictOnDelete();
            $table->string('academic_standing', 120)->nullable();
            $table->string('status', 20)->default('pending');
            $table->date('started_at')->nullable();
            $table->date('separated_at')->nullable();
            $table->string('separation_reason')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamps();

            $table->index(['status', 'personnel_type_id']);
            $table->index(['last_name', 'first_name']);
        });

        Schema::create('personnel_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_id')->unique()->constrained('personnel')->cascadeOnDelete();
            // internal
            $table->string('email_work')->nullable();
            $table->string('phone_mobile', 30)->nullable();
            // confidential
            $table->date('birth_date')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->string('emergency_contact_relation', 80)->nullable();
            // sensitive — encrypted at rest
            $table->text('national_id')->nullable();
            $table->string('national_id_hash', 64)->nullable()->unique();
            $table->text('address_registered')->nullable();
            $table->text('address_current')->nullable();
            $table->text('bank_name')->nullable();
            $table->text('bank_account')->nullable();
            $table->text('health_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('personnel_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->string('category', 60);
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['personnel_id', 'category']);
        });

        Schema::create('personnel_educations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->string('level', 80);
            $table->string('degree')->nullable();
            $table->string('major')->nullable();
            $table->string('institution')->nullable();
            $table->unsignedSmallInteger('graduated_year')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('personnel_id');
        });

        Schema::create('personnel_trainings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->string('course');
            $table->string('organizer')->nullable();
            $table->decimal('hours', 6, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->foreignId('certificate_document_id')->nullable()->constrained('personnel_documents')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['personnel_id', 'start_date']);
        });

        Schema::create('personnel_licenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->string('license_type', 120);
            $table->string('license_number', 80);
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['personnel_id', 'expires_at']);
            $table->index('expires_at');
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('personnel_id')->constrained('personnel')->restrictOnDelete();
            $table->string('kind', 20);
            $table->foreignId('org_unit_id')->constrained('org_units')->restrictOnDelete();
            $table->foreignId('position_id')->constrained('positions')->restrictOnDelete();
            $table->foreignId('acting_for_assignment_id')->nullable()->constrained('assignments')->restrictOnDelete();
            $table->string('order_number', 120)->nullable();
            $table->date('order_date')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('end_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['personnel_id', 'kind', 'effective_from', 'effective_to'], 'assignments_personnel_kind_effective_idx');
            $table->index(['org_unit_id', 'kind', 'effective_from', 'effective_to'], 'assignments_unit_kind_effective_idx');
            $table->index('position_id');
        });

        Schema::create('field_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 60);
            $table->string('field', 80);
            $table->string('class', 20);
            $table->string('editable_by', 30)->default('hr_only');
            $table->timestamps();

            $table->unique(['entity', 'field']);
        });

        Schema::create('personnel_change_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('entity', 60);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('payload');
            $table->json('evidence_document_ids')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['personnel_id', 'status']);
        });

        Schema::create('sensitive_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->json('acting_as')->nullable();
            $table->foreignId('personnel_id')->constrained('personnel')->restrictOnDelete();
            $table->json('fields');
            $table->text('reason');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['personnel_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensitive_access_logs');
        Schema::dropIfExists('personnel_change_requests');
        Schema::dropIfExists('field_classifications');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('personnel_licenses');
        Schema::dropIfExists('personnel_trainings');
        Schema::dropIfExists('personnel_educations');
        Schema::dropIfExists('personnel_documents');
        Schema::dropIfExists('personnel_profiles');
        Schema::dropIfExists('personnel');
        Schema::dropIfExists('personnel_types');
    }
};
