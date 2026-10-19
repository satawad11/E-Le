<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saraban_document_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('saraban_documents')->onDelete('cascade');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->text('ocr_text')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saraban_document_attachments');
    }
};
