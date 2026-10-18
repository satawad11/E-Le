<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R02-7 — สารบรรณอิเล็กทรอนิกส์ — department_id เปลี่ยนเป็น org_unit_id
 *
 * สร้างในรูปสุดท้ายของระบบเดิม (รวม alter ทุกไฟล์ใน database/migrations-legacy/)
 * ตามแผน REBUILD-2569 §4.2 "เก็บโครงสร้าง ล้างข้อมูล" · ตัดคอลัมน์/FK ที่อ้างตารางที่ถูกตัด
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saraban_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('code', 255)->nullable();
            $table->string('color', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('saraban_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->integer('year_be');
            $table->unsignedBigInteger('org_unit_id');
            $table->unsignedBigInteger('document_type_id');
            $table->unsignedBigInteger('running_number')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['year_be', 'org_unit_id', 'document_type_id'], 'sequence_unique');
            $table->foreign(['org_unit_id'], 'saraban_document_sequences_org_unit_id_foreign')->references(['id'])->on('org_units')->restrictOnDelete();
            $table->foreign(['document_type_id'], 'saraban_document_sequences_document_type_id_foreign')->references(['id'])->on('saraban_document_types')->cascadeOnDelete();
        });

        Schema::create('saraban_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->enum('creation_mode', ['physical', 'digital'])->default('physical');
            $table->string('document_number', 255);
            $table->string('incoming_document_number', 255)->nullable();
            $table->date('incoming_date')->nullable();
            $table->string('title', 255);
            $table->date('document_date')->nullable();
            $table->string('to', 255)->nullable();
            $table->string('reference', 255)->nullable();
            $table->text('attachments_note')->nullable();
            $table->unsignedBigInteger('type_id');
            $table->enum('priority', ['normal', 'urgent', 'very_urgent', 'most_urgent'])->default('normal');
            $table->enum('secrecy', ['normal', 'secret', 'top_secret', 'most_secret'])->default('normal');
            $table->enum('status', ['draft', 'pending_review', 'pending_approval', 'approved_pending_signature', 'signed', 'dispatched', 'archived'])->default('draft');
            $table->boolean('allow_download')->default(true);
            $table->text('remark')->nullable();
            $table->longText('content')->nullable();
            $table->string('template_type', 255)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('org_unit_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unique(['document_number'], 'saraban_documents_document_number_unique');
            $table->unique(['uuid'], 'saraban_documents_uuid_unique');
            $table->foreign(['created_by'], 'saraban_documents_created_by_foreign')->references(['id'])->on('users')->restrictOnDelete();
            $table->foreign(['org_unit_id'], 'saraban_documents_org_unit_id_foreign')->references(['id'])->on('org_units')->restrictOnDelete();
            $table->foreign(['type_id'], 'saraban_documents_type_id_foreign')->references(['id'])->on('saraban_document_types')->cascadeOnDelete();
        });

        Schema::create('saraban_document_incoming', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->date('receive_date');
            $table->string('from_agency', 255);
            $table->unsignedBigInteger('receiver_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->foreign(['document_id'], 'saraban_document_incoming_document_id_foreign')->references(['id'])->on('saraban_documents')->cascadeOnDelete();
            $table->foreign(['receiver_id'], 'saraban_document_incoming_receiver_id_foreign')->references(['id'])->on('users')->restrictOnDelete();
        });

        Schema::create('saraban_document_outgoing', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->date('dispatch_date');
            $table->string('to_agency', 255);
            $table->string('dispatch_method', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->foreign(['document_id'], 'saraban_document_outgoing_document_id_foreign')->references(['id'])->on('saraban_documents')->cascadeOnDelete();
        });

        Schema::create('saraban_document_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->string('name', 255)->nullable();
            $table->enum('type', ['file', 'link'])->default('file');
            $table->string('path', 255);
            $table->string('mime_type', 255)->nullable();
            $table->unsignedBigInteger('uploaded_by');
            $table->text('ocr_text')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->foreign(['document_id'], 'saraban_document_attachments_document_id_foreign')->references(['id'])->on('saraban_documents')->cascadeOnDelete();
            $table->foreign(['uploaded_by'], 'saraban_document_attachments_uploaded_by_foreign')->references(['id'])->on('users')->restrictOnDelete();
        });

        Schema::create('saraban_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('action', 255);
            $table->string('ip_address', 255)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->foreign(['document_id'], 'saraban_audit_logs_document_id_foreign')->references(['id'])->on('saraban_documents')->nullOnDelete();
            $table->foreign(['user_id'], 'saraban_audit_logs_user_id_foreign')->references(['id'])->on('users')->restrictOnDelete();
        });

        Schema::create('saraban_document_receivers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->foreign(['document_id'], 'saraban_document_receivers_document_id_foreign')->references(['id'])->on('saraban_documents')->cascadeOnDelete();
            $table->foreign(['user_id'], 'saraban_document_receivers_user_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
        });

        Schema::create('saraban_document_user_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_read')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['document_id', 'user_id'], 'saraban_document_user_states_document_id_user_id_unique');
            $table->foreign(['document_id'], 'saraban_document_user_states_document_id_foreign')->references(['id'])->on('saraban_documents')->cascadeOnDelete();
            $table->foreign(['user_id'], 'saraban_document_user_states_user_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
        });

        Schema::create('saraban_document_workflow_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->json('steps_config');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('saraban_document_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 255);
            $table->text('comment')->nullable();
            $table->timestamp('action_at');
            $table->integer('step_order');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->foreign(['document_id'], 'saraban_document_workflow_steps_document_id_foreign')->references(['id'])->on('saraban_documents')->cascadeOnDelete();
            $table->foreign(['user_id'], 'saraban_document_workflow_steps_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
        });

        Schema::create('saraban_document_signatures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('signer_id');
            $table->string('signature_hash', 255);
            $table->string('document_hash', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('mode', 20)->default('esignature');
            $table->timestamp('signed_at');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['signature_hash'], 'saraban_document_signatures_signature_hash_unique');
            $table->foreign(['document_id'], 'saraban_document_signatures_document_id_foreign')->references(['id'])->on('saraban_documents')->cascadeOnDelete();
            $table->foreign(['signer_id'], 'saraban_document_signatures_signer_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
        });

        Schema::create('saraban_document_circulars', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('sender_id');
            $table->text('message')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->foreign(['document_id'], 'saraban_document_circulars_document_id_foreign')->references(['id'])->on('saraban_documents')->cascadeOnDelete();
            $table->foreign(['sender_id'], 'saraban_document_circulars_sender_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
        });

        Schema::create('saraban_document_circular_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('circular_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_acknowledged')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['circular_id', 'user_id'], 'saraban_document_circular_recipients_circular_id_user_id_unique');
            $table->foreign(['circular_id'], 'saraban_document_circular_recipients_circular_id_foreign')->references(['id'])->on('saraban_document_circulars')->cascadeOnDelete();
            $table->foreign(['user_id'], 'saraban_document_circular_recipients_user_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saraban_document_circular_recipients');
        Schema::dropIfExists('saraban_document_circulars');
        Schema::dropIfExists('saraban_document_signatures');
        Schema::dropIfExists('saraban_document_workflow_steps');
        Schema::dropIfExists('saraban_document_workflow_templates');
        Schema::dropIfExists('saraban_document_user_states');
        Schema::dropIfExists('saraban_document_receivers');
        Schema::dropIfExists('saraban_audit_logs');
        Schema::dropIfExists('saraban_document_attachments');
        Schema::dropIfExists('saraban_document_outgoing');
        Schema::dropIfExists('saraban_document_incoming');
        Schema::dropIfExists('saraban_documents');
        Schema::dropIfExists('saraban_document_sequences');
        Schema::dropIfExists('saraban_document_types');
    }
};
