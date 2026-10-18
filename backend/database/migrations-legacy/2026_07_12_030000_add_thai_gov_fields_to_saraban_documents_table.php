<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saraban_documents', function (Blueprint $table) {
            $table->date('document_date')->nullable()->after('title');
            $table->string('to')->nullable()->after('document_date');
            $table->string('reference')->nullable()->after('to');
            $table->text('attachments_note')->nullable()->after('reference');
            $table->text('remark')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('saraban_documents', function (Blueprint $table) {
            $table->dropColumn(['document_date', 'to', 'reference', 'attachments_note', 'remark']);
        });
    }
};
