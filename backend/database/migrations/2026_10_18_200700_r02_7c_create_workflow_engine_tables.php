<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R02-7 — Workflow engine (CORE-05) — ไม่มี workflow_shadow_comparisons และไม่อ้าง roles
 *
 * สร้างในรูปสุดท้ายของระบบเดิม (รวม alter ทุกไฟล์ใน database/migrations-legacy/)
 * ตามแผน REBUILD-2569 §4.2 "เก็บโครงสร้าง ล้างข้อมูล" · ตัดคอลัมน์/FK ที่อ้างตารางที่ถูกตัด
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->string('code', 100);
            $table->string('module', 50);
            $table->string('name', 255);
            $table->string('subject_type', 255)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['code'], 'workflow_definitions_code_unique');
            $table->index(['module', 'is_active'], 'workflow_definitions_module_is_active_index');
            $table->unique(['uuid'], 'workflow_definitions_uuid_unique');
        });

        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('workflow_definition_id');
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['effective_from', 'effective_to'], 'workflow_versions_effective_from_effective_to_index');
            $table->unique(['uuid'], 'workflow_versions_uuid_unique');
            $table->index(['workflow_definition_id', 'status'], 'workflow_versions_workflow_definition_id_status_index');
            $table->unique(['workflow_definition_id', 'version'], 'workflow_versions_workflow_definition_id_version_unique');
            $table->foreign(['published_by'], 'workflow_versions_published_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['workflow_definition_id'], 'workflow_versions_workflow_definition_id_foreign')->references(['id'])->on('workflow_definitions')->restrictOnDelete();
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('workflow_version_id');
            $table->string('code', 100);
            $table->string('name', 255);
            $table->unsignedInteger('step_order');
            $table->string('type', 30)->default('approval');
            $table->string('assignee_type', 30)->default('role');
            $table->unsignedBigInteger('assignee_user_id')->nullable();
            $table->string('assignee_permission', 255)->nullable();
            $table->string('completion_policy', 10)->default('any');
            $table->json('conditions')->nullable();
            $table->unsignedInteger('sla_hours')->nullable();
            $table->string('on_timeout', 30)->nullable();
            $table->unsignedBigInteger('escalate_to_step_id')->nullable();
            $table->boolean('is_final')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['uuid'], 'workflow_steps_uuid_unique');
            $table->unique(['workflow_version_id', 'code'], 'workflow_steps_workflow_version_id_code_unique');
            $table->unique(['workflow_version_id', 'step_order'], 'workflow_steps_workflow_version_id_step_order_unique');
            $table->foreign(['assignee_user_id'], 'workflow_steps_assignee_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['workflow_version_id'], 'workflow_steps_workflow_version_id_foreign')->references(['id'])->on('workflow_versions')->cascadeOnDelete();
        });

        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('workflow_version_id');
            $table->unsignedBigInteger('from_step_id')->nullable();
            $table->unsignedBigInteger('to_step_id')->nullable();
            $table->string('action', 30);
            $table->string('name', 255)->nullable();
            $table->string('result', 30)->nullable();
            $table->json('conditions')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('requires_comment')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['workflow_version_id', 'from_step_id', 'action'], 'workflow_transition_lookup');
            $table->unique(['uuid'], 'workflow_transitions_uuid_unique');
            $table->foreign(['from_step_id'], 'workflow_transitions_from_step_id_foreign')->references(['id'])->on('workflow_steps')->cascadeOnDelete();
            $table->foreign(['to_step_id'], 'workflow_transitions_to_step_id_foreign')->references(['id'])->on('workflow_steps')->cascadeOnDelete();
            $table->foreign(['workflow_version_id'], 'workflow_transitions_workflow_version_id_foreign')->references(['id'])->on('workflow_versions')->cascadeOnDelete();
        });

        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('workflow_version_id');
            $table->string('subject_type', 255);
            $table->unsignedBigInteger('subject_id');
            $table->string('status', 30)->default('running');
            $table->unsignedBigInteger('current_step_id')->nullable();
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->boolean('is_shadow')->default(true);
            $table->string('correlation_id', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['subject_type', 'subject_id', 'workflow_version_id'], 'workflow_instance_subject_unique');
            $table->index(['status', 'is_shadow'], 'workflow_instances_status_is_shadow_index');
            $table->index(['subject_type', 'subject_id'], 'workflow_instances_subject_type_subject_id_index');
            $table->unique(['uuid'], 'workflow_instances_uuid_unique');
            $table->foreign(['current_step_id'], 'workflow_instances_current_step_id_foreign')->references(['id'])->on('workflow_steps')->nullOnDelete();
            $table->foreign(['initiated_by'], 'workflow_instances_initiated_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['workflow_version_id'], 'workflow_instances_workflow_version_id_foreign')->references(['id'])->on('workflow_versions')->restrictOnDelete();
        });

        Schema::create('workflow_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('workflow_instance_id');
            $table->unsignedBigInteger('workflow_step_id');
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('sequence')->default(0);
            $table->unsignedBigInteger('assignee_user_id')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->unsignedBigInteger('effective_actor_id')->nullable();
            $table->unsignedBigInteger('delegated_by_id')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['uuid'], 'workflow_tasks_uuid_unique');
            $table->index(['workflow_instance_id', 'status'], 'workflow_tasks_workflow_instance_id_status_index');
            $table->foreign(['assignee_user_id'], 'workflow_tasks_assignee_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['completed_by'], 'workflow_tasks_completed_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['delegated_by_id'], 'workflow_tasks_delegated_by_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['effective_actor_id'], 'workflow_tasks_effective_actor_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['workflow_instance_id'], 'workflow_tasks_workflow_instance_id_foreign')->references(['id'])->on('workflow_instances')->cascadeOnDelete();
            $table->foreign(['workflow_step_id'], 'workflow_tasks_workflow_step_id_foreign')->references(['id'])->on('workflow_steps')->restrictOnDelete();
        });

        Schema::create('workflow_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('workflow_instance_id');
            $table->unsignedBigInteger('workflow_task_id')->nullable();
            $table->string('action', 30);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('effective_actor_id')->nullable();
            $table->unsignedBigInteger('delegated_by_id')->nullable();
            $table->unsignedBigInteger('from_step_id')->nullable();
            $table->unsignedBigInteger('to_step_id')->nullable();
            $table->text('comment')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['correlation_id'], 'workflow_actions_correlation_id_index');
            $table->unique(['uuid'], 'workflow_actions_uuid_unique');
            $table->index(['workflow_instance_id', 'created_at'], 'workflow_actions_workflow_instance_id_created_at_index');
            $table->foreign(['actor_id'], 'workflow_actions_actor_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['delegated_by_id'], 'workflow_actions_delegated_by_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['effective_actor_id'], 'workflow_actions_effective_actor_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['from_step_id'], 'workflow_actions_from_step_id_foreign')->references(['id'])->on('workflow_steps')->nullOnDelete();
            $table->foreign(['to_step_id'], 'workflow_actions_to_step_id_foreign')->references(['id'])->on('workflow_steps')->nullOnDelete();
            $table->foreign(['workflow_instance_id'], 'workflow_actions_workflow_instance_id_foreign')->references(['id'])->on('workflow_instances')->cascadeOnDelete();
            $table->foreign(['workflow_task_id'], 'workflow_actions_workflow_task_id_foreign')->references(['id'])->on('workflow_tasks')->nullOnDelete();
        });

        Schema::create('workflow_delegations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('delegator_id');
            $table->unsignedBigInteger('delegate_id');
            $table->unsignedBigInteger('workflow_definition_id')->nullable();
            $table->string('delegation_type', 30)->default('MANAGER_DELEGATE');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('legacy_delegation_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['delegate_id', 'effective_from', 'effective_to'], 'workflow_delegation_effective');
            $table->index(['delegator_id', 'is_active'], 'workflow_delegations_delegator_id_is_active_index');
            $table->unique(['legacy_delegation_id'], 'workflow_delegations_legacy_delegation_id_unique');
            $table->unique(['uuid'], 'workflow_delegations_uuid_unique');
            $table->foreign(['created_by'], 'workflow_delegations_created_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['delegate_id'], 'workflow_delegations_delegate_id_foreign')->references(['id'])->on('users')->restrictOnDelete();
            $table->foreign(['delegator_id'], 'workflow_delegations_delegator_id_foreign')->references(['id'])->on('users')->restrictOnDelete();
            $table->foreign(['workflow_definition_id'], 'workflow_delegations_workflow_definition_id_foreign')->references(['id'])->on('workflow_definitions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_delegations');
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('workflow_tasks');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflow_definitions');
    }
};
