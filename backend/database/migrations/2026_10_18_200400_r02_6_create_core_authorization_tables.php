<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R02-6 — CORE-04 Authorization (docs/modules/CORE-04-RBAC-ABAC.md) แทน spatie/laravel-permission (ADR-R-003)
 *
 * - `modules`, `module_features`, `permissions` สร้างจาก manifest ด้วย `modules:sync` (R-04) เท่านั้น
 *   ไม่สร้างจากหน้าจอ · permission ที่หายจาก manifest ถูก mark `retired_at` ไม่ลบ
 * - สิทธิ์ = คู่ (permission, scope) ต่อแหล่งที่มา: `template_permissions.scope`
 * - `role_grants.expires_at` บังคับ · `permission_overrides.expires_at` null ได้เฉพาะ effect = deny (ตรวจใน service)
 * - `permission_audit_logs` เพิ่มได้อย่างเดียว
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->string('version', 20);
            $table->boolean('enabled')->default(true);
            $table->string('manifest_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('module_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['module_id', 'code']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->foreignId('module_feature_id')->constrained('module_features')->restrictOnDelete();
            $table->string('action', 60);
            $table->string('label');
            $table->json('allowed_scopes');
            $table->json('requires')->nullable();
            $table->string('risk', 10)->default('low');
            $table->json('rules')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index(['module_feature_id', 'action']);
        });

        Schema::create('permission_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('module_id')->nullable()->constrained('modules')->restrictOnDelete();
            $table->string('code', 100)->unique();
            $table->string('name');
            $table->string('kind', 20);
            $table->text('description')->nullable();
            $table->json('attributes_schema')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index(['module_id', 'kind']);
        });

        Schema::create('template_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('permission_templates')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->restrictOnDelete();
            $table->string('scope', 20);
            $table->timestamps();

            $table->unique(['template_id', 'permission_id']);
        });

        Schema::create('position_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->nullable()->constrained('positions')->cascadeOnDelete();
            $table->unsignedTinyInteger('org_unit_type_level')->nullable();
            $table->foreignId('template_id')->constrained('permission_templates')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['position_id', 'org_unit_type_level', 'template_id'], 'position_templates_unique');
            $table->index('org_unit_type_level');
        });

        Schema::create('role_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('template_id')->constrained('permission_templates')->restrictOnDelete();
            $table->json('attributes')->nullable();
            $table->string('order_number', 120);
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('end_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'ended_at', 'expires_at']);
            $table->index('template_id');
        });

        Schema::create('permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->restrictOnDelete();
            $table->string('effect', 10);
            $table->string('scope', 20)->nullable();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('expires_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'ended_at', 'expires_at']);
            $table->index(['permission_id', 'effect']);
        });

        Schema::create('permission_change_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->json('target');
            $table->json('diff');
            $table->unsignedInteger('affected_user_count')->default(0);
            $table->text('reason')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('access_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_at');
            $table->string('status', 20)->default('open');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_at']);
        });

        Schema::create('access_review_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_review_id')->constrained('access_reviews')->cascadeOnDelete();
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 20)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['access_review_id', 'subject_type', 'subject_id'], 'access_review_items_subject_unique');
            $table->index(['reviewer_id', 'decision']);
        });

        Schema::create('permission_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('change_request_id')->nullable()->constrained('permission_change_requests')->restrictOnDelete();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_audit_logs');
        Schema::dropIfExists('access_review_items');
        Schema::dropIfExists('access_reviews');
        Schema::dropIfExists('permission_change_requests');
        Schema::dropIfExists('permission_overrides');
        Schema::dropIfExists('role_grants');
        Schema::dropIfExists('position_templates');
        Schema::dropIfExists('template_permissions');
        Schema::dropIfExists('permission_templates');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('module_features');
        Schema::dropIfExists('modules');
    }
};
