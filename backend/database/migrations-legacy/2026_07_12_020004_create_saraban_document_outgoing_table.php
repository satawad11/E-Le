<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saraban_document_outgoing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('saraban_documents')->onDelete('cascade');
            $table->date('dispatch_date');
            $table->string('to_agency');
            $table->string('dispatch_method')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saraban_document_outgoing');
    }
};
