<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-05 · 05-1 — รากฐาน Workflow Engine (additive ทั้งหมด)
 *
 * ปัญหาที่แก้ (CORE-WF-001): สายอนุมัติปัจจุบันอยู่ใน `approval_steps` ซึ่งเป็น
 * ตารางเดียวระดับ global ไม่มี version ไม่มี effective date และตรรกะการเดินสาย
 * ฝังอยู่ใน controller ผลคือ **แก้สายอนุมัติวันนี้ = เปลี่ยนความหมายของใบลา
 * ที่ค้างอยู่ในระบบทันที**
 *
 * แนวทาง — เพิ่มอย่างเดียว ไม่แตะของเดิม:
 *   - `approval_steps`, `leave_approvals`, `leaves.approval_level` **คงไว้ทั้งหมด**
 *     และยังเป็น source of truth จนกว่าจะ cutover (§2.1 ของแผน)
 *   - ตารางชุดนี้เริ่มต้นทำงานใน shadow mode เท่านั้น
 *
 * ทุก instance pin `workflow_version_id` ตาม `docs/07-WORKFLOW-ENGINE.md`
 * การแก้ workflow จึงไม่ย้อนไปเปลี่ยนรายการที่กำลังรันอยู่
 *
 * หมายเหตุเรื่องชนิดคอลัมน์: ใช้ `string` แทน `enum` สำหรับค่าที่เป็นชุดจำกัด
 * เพราะชุดทดสอบรันบน SQLite และการเพิ่มค่าใหม่ใน enum ต้อง ALTER TABLE
 * ซึ่งขัดกับหลักการ additive ของโปรเจกต์ (ค่าที่ยอมรับบังคับที่ชั้น application)
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * นิยาม workflow หนึ่งชุด เช่น "สายอนุมัติใบลา" — ตัวมันเองไม่มีขั้นตอน
         * ขั้นตอนอยู่ใน version เพื่อให้แก้ได้โดยไม่กระทบของเดิม
         */
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 100)->unique();          // leave.approval, saraban.document
            $table->string('module', 50);                   // leave | saraban | attendance | ot
            $table->string('name');
            $table->string('subject_type')->nullable();     // Eloquent morph class ของเรื่องที่เดินสาย
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['module', 'is_active']);
        });

        /*
         * เวอร์ชันของ workflow — effective-dated ตาม PROJECT_RULES ข้อ 7
         * ห้ามแก้ version ที่มี instance รันอยู่ ให้ออก version ใหม่แทน
         */
        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft'); // draft | active | retired
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['workflow_definition_id', 'version']);
            $table->index(['workflow_definition_id', 'status']);
            $table->index(['effective_from', 'effective_to']);
        });

        /*
         * ขั้นตอนภายในเวอร์ชันหนึ่ง
         *
         * รองรับ sequential / parallel / conditional ตั้งแต่ schema แม้ Leave v1
         * จะใช้แค่ sequential — เพื่อไม่ต้อง ALTER schema ตอนเปิดใช้ของพวกนี้
         */
        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workflow_version_id')->constrained('workflow_versions')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->unsignedInteger('step_order');

            // approval | notification | system | parallel | conditional
            $table->string('type', 30)->default('approval');

            /*
             * ใครเป็นผู้รับผิดชอบขั้นนี้
             *   role        ตามบทบาท (สายอนุมัติเดิมใช้แบบนี้)
             *   user        ระบุตัวบุคคล
             *   permission  ผู้ถือสิทธิ์ที่กำหนด
             *   org_manager หัวหน้าหน่วยงานของผู้ยื่น (org_unit_managers · LEGACY-04)
             *   initiator   ผู้ยื่นเอง (ใช้กับขั้น "ส่งกลับแก้ไข")
             */
            $table->string('assignee_type', 30)->default('role');
            $table->foreignId('assignee_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assignee_permission')->nullable();

            // any = คนใดคนหนึ่งพอ · all = ต้องครบทุกคน (ใช้กับ parallel)
            $table->string('completion_policy', 10)->default('any');

            // เงื่อนไขของ conditional step — ประเมินโดย application ไม่ใช่ SQL
            $table->json('conditions')->nullable();

            // timeout / escalation — ยังไม่เปิดใช้ใน Leave v1
            $table->unsignedInteger('sla_hours')->nullable();
            $table->string('on_timeout', 30)->nullable();   // escalate | auto_approve | notify
            $table->unsignedBigInteger('escalate_to_step_id')->nullable();

            $table->boolean('is_final')->default(false);
            $table->timestamps();

            $table->unique(['workflow_version_id', 'code']);
            $table->unique(['workflow_version_id', 'step_order']);
        });

        /*
         * เส้นทางระหว่างขั้นตอน — approve/reject/return/cancel/escalate/timeout
         *
         * `to_step_id` เป็น null หมายถึงจบ workflow ด้วยผลลัพธ์ `result`
         */
        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workflow_version_id')->constrained('workflow_versions')->cascadeOnDelete();
            $table->foreignId('from_step_id')->nullable()->constrained('workflow_steps')->cascadeOnDelete();
            $table->foreignId('to_step_id')->nullable()->constrained('workflow_steps')->cascadeOnDelete();

            // approve | reject | return | cancel | delegate | escalate | timeout
            $table->string('action', 30);
            $table->string('name')->nullable();

            // ผลลัพธ์ของ instance เมื่อ transition นี้ทำให้ workflow จบ
            $table->string('result', 30)->nullable();       // completed | rejected | cancelled | returned

            $table->json('conditions')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('requires_comment')->default(false);
            $table->timestamps();

            // ตั้งชื่อ index เองเพราะชื่ออัตโนมัติยาวเกิน 64 ตัวอักษรที่ MySQL รับได้
            $table->index(['workflow_version_id', 'from_step_id', 'action'], 'workflow_transition_lookup');
        });

        /*
         * หนึ่ง instance = เรื่องหนึ่งเรื่องที่กำลังเดินสาย (ใบลาหนึ่งใบ / หนังสือหนึ่งฉบับ)
         *
         * `workflow_version_id` คือการ pin ตามกฎของ docs/07 — แก้ definition
         * ภายหลังไม่กระทบ instance นี้
         *
         * `is_shadow` = instance ที่สร้างไว้เพื่อเทียบผลกับตรรกะเดิมเท่านั้น
         * ยังไม่ได้เป็นผู้ตัดสินสถานะจริงของเรื่อง
         */
        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workflow_version_id')->constrained('workflow_versions')->restrictOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            // running | completed | rejected | cancelled | returned
            $table->string('status', 30)->default('running');

            $table->foreignId('current_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_shadow')->default(true);
            $table->string('correlation_id', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['status', 'is_shadow']);
            $table->unique(['subject_type', 'subject_id', 'workflow_version_id'], 'workflow_instance_subject_unique');
        });

        /*
         * งานที่รอคนทำในแต่ละขั้น — หนึ่งแถวต่อหนึ่งขั้นของ instance
         * (parallel step จะมีหลายแถวใน sequence เดียวกัน)
         */
        Schema::create('workflow_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->foreignId('workflow_step_id')->constrained('workflow_steps')->restrictOnDelete();

            // pending | approved | rejected | returned | skipped | cancelled
            $table->string('status', 30)->default('pending');

            $table->unsignedInteger('sequence')->default(0);
            $table->foreignId('assignee_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * ผู้ลงมือจริง vs ผู้ที่ควรเป็นเจ้าของงาน
             * ถ้าใช้สิทธิ์รักษาการ/รับมอบอำนาจ ทั้งสองค่าจะต่างกัน และ
             * `delegated_by_id` บอกว่ารับมอบมาจากใคร
             */
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('effective_actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delegated_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['workflow_instance_id', 'status']);
            $table->index(['assignee_role_id', 'status']);
        });

        /*
         * บันทึกการกระทำทุกครั้งบน workflow — append-oriented ตาม PROJECT_RULES ข้อ 8
         * ห้าม update/delete แถวในตารางนี้
         */
        Schema::create('workflow_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->foreignId('workflow_task_id')->nullable()->constrained('workflow_tasks')->nullOnDelete();

            $table->string('action', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('effective_actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delegated_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('from_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            $table->foreignId('to_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();

            $table->text('comment')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['workflow_instance_id', 'created_at']);
            $table->index('correlation_id');
        });

        /*
         * การมอบอำนาจระดับ workflow
         *
         * ระบบมี `user_delegations` อยู่แล้วและยังใช้เป็น source of truth
         * ตารางนี้ **อ้างอิงกลับ** ผ่าน `legacy_delegation_id` เพื่อไม่ให้เกิด
         * แหล่งข้อมูลสองแหล่งที่ขัดกัน (บทเรียนจาก DB-RISK-009 ใน LEGACY-04)
         *
         * ที่เพิ่มมาจากของเดิมคือ ขอบเขต: มอบเฉพาะ workflow ใด หรือเฉพาะบทบาทใด
         */
        Schema::create('workflow_delegations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('delegator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('workflow_definition_id')->nullable()->constrained('workflow_definitions')->cascadeOnDelete();
            $table->foreignId('scope_role_id')->nullable()->constrained('roles')->nullOnDelete();

            // MANAGER_DELEGATE | ACTING — ให้ตรงกับคำศัพท์ของ org_unit_managers
            $table->string('delegation_type', 30)->default('MANAGER_DELEGATE');

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('reason')->nullable();

            $table->unsignedBigInteger('legacy_delegation_id')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['delegate_id', 'effective_from', 'effective_to'], 'workflow_delegation_effective');
            $table->index(['delegator_id', 'is_active']);
        });

        /*
         * 05-3 Shadow mode — บันทึกผลของ engine ใหม่เทียบกับตรรกะเดิม
         *
         * ตราบใดที่ยังมี mismatch ค้างอยู่ **ห้าม cutover** ตามกฎข้อ 9
         * ตารางนี้คือหลักฐานที่ใช้ตัดสินใจข้อนั้น
         */
        Schema::create('workflow_shadow_comparisons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            // current_step | next_approver | available_transitions | approve | reject
            $table->string('checkpoint', 50);

            $table->json('legacy_result')->nullable();
            $table->json('engine_result')->nullable();
            $table->boolean('matched');
            $table->json('mismatch_details')->nullable();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['matched', 'checkpoint']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        // ลำดับย้อนกลับของ foreign key
        Schema::dropIfExists('workflow_shadow_comparisons');
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
