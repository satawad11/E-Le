<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saraban_documents', function (Blueprint $table) {
            $table->string('incoming_document_number')->nullable()->after('document_number');
            $table->date('incoming_date')->nullable()->after('incoming_document_number');
        });
    }

    public function down(): void
    {
        Schema::table('saraban_documents', function (Blueprint $table) {
            $table->dropColumn(['incoming_document_number', 'incoming_date']);
        });
    }
};
