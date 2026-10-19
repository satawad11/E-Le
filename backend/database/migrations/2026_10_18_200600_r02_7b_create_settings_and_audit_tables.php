<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R02-7 — ตั้งค่าระบบ บันทึก audit กลาง บันทึกการเข้าถึงข้อมูลส่วนบุคคล และทะเบียนปฏิบัติหน้าที่แทน
 *
 * สร้างในรูปสุดท้ายของระบบเดิม (รวม alter ทุกไฟล์ใน database/migrations-legacy/)
 * ตามแผน REBUILD-2569 §4.2 "เก็บโครงสร้าง ล้างข้อมูล" · ตัดคอลัมน์/FK ที่อ้างตารางที่ถูกตัด
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 255);
            $table->text('value')->nullable();
            $table->string('type', 255)->default('string');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['key'], 'settings_key_unique');
        });

        Schema::create('setting_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('changes');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->foreign(['user_id'], 'setting_audit_logs_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('effective_actor_id')->nullable();
            $table->string('action', 120);
            $table->string('resource_type', 120);
            $table->string('resource_id', 64)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['actor_id', 'created_at'], 'audit_logs_actor_id_created_at_index');
            $table->index(['correlation_id'], 'audit_logs_correlation_id_index');
            $table->index(['resource_type', 'resource_id', 'created_at'], 'audit_resource_timeline');
            $table->foreign(['actor_id'], 'audit_logs_actor_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['effective_actor_id'], 'audit_logs_effective_actor_id_foreign')->references(['id'])->on('users')->nullOnDelete();
        });

        Schema::create('personal_data_access_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedInteger('subject_count')->nullable();
            $table->string('resource', 80);
            $table->string('purpose', 160)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['actor_id', 'created_at'], 'personal_data_access_logs_actor_id_created_at_index');
            $table->index(['subject_id', 'created_at'], 'personal_data_access_logs_subject_id_created_at_index');
            $table->foreign(['actor_id'], 'personal_data_access_logs_actor_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
            $table->foreign(['subject_id'], 'personal_data_access_logs_subject_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
        });

        Schema::create('user_delegations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('delegate_id');
            $table->string('delegation_type', 20)->default('on_behalf');
            $table->string('acting_position', 255)->nullable();
            $table->string('order_number', 100)->nullable();
            $table->date('order_date')->nullable();
            $table->string('note', 500)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->string('status', 20)->default('approved');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->foreign(['approved_by'], 'user_delegations_approved_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['delegate_id'], 'user_delegations_delegate_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
            $table->foreign(['requested_by'], 'user_delegations_requested_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['revoked_by'], 'user_delegations_revoked_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['user_id'], 'user_delegations_user_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_delegations');
        Schema::dropIfExists('personal_data_access_logs');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('setting_audit_logs');
        Schema::dropIfExists('settings');
    }
};
