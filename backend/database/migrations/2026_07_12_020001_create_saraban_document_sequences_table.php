<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saraban_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->integer('year_be');
            $table->foreignId('department_id')->constrained('departments')->onDelete('cascade');
            $table->foreignId('document_type_id')->constrained('saraban_document_types')->onDelete('cascade');
            $table->unsignedBigInteger('running_number')->default(0);
            $table->timestamps();

            $table->unique(['year_be', 'department_id', 'document_type_id'], 'sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saraban_document_sequences');
    }
};
