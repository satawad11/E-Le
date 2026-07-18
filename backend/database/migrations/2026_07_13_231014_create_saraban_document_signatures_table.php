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
        Schema::create('saraban_document_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('saraban_documents')->onDelete('cascade');
            $table->foreignId('signer_id')->constrained('users')->onDelete('cascade');
            $table->string('signature_hash')->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saraban_document_signatures');
    }
};
