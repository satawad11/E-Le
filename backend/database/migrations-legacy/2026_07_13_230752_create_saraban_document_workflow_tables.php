<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('saraban_document_workflow_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('steps_config'); // e.g. [{"order":1, "role_name":"head", "action":"review"}, {"order":2, "role_name":"director", "action":"sign"}]
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('saraban_document_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('saraban_documents')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null'); // Who performed the action
            $table->string('action'); // e.g. submitted, reviewed, approved, signed, rejected
            $table->text('comment')->nullable();
            $table->timestamp('action_at');
            $table->integer('step_order');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saraban_document_workflow_steps');
        Schema::dropIfExists('saraban_document_workflow_templates');
    }
};
