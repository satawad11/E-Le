<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saraban_document_user_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('saraban_documents')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->boolean('is_read')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();

            $table->unique(['document_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saraban_document_user_states');
    }
};
