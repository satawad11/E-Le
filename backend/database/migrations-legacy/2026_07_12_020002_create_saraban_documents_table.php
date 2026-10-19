<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saraban_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique();
            $table->string('title');
            $table->foreignId('type_id')->constrained('saraban_document_types')->onDelete('cascade');
            $table->enum('priority', ['normal', 'urgent', 'very_urgent', 'most_urgent'])->default('normal');
            $table->enum('secrecy', ['normal', 'secret', 'top_secret', 'most_secret'])->default('normal');
            $table->enum('status', ['draft', 'pending_review', 'pending_approval', 'approved_pending_signature', 'signed', 'dispatched', 'archived'])->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('department_id')->constrained('departments');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saraban_documents');
    }
};
