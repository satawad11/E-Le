<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saraban_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('saraban_documents', 'creation_mode')) {
                $table->enum('creation_mode', ['physical', 'digital'])->default('physical')->after('id');
            }
            if (! Schema::hasColumn('saraban_documents', 'content')) {
                $table->longText('content')->nullable()->after('remark');
            }
            if (! Schema::hasColumn('saraban_documents', 'template_type')) {
                $table->string('template_type')->nullable()->after('content');
            }
        });
    }

    public function down(): void
    {
        Schema::table('saraban_documents', function (Blueprint $table) {
            $table->dropColumn(['creation_mode', 'content', 'template_type']);
        });
    }
};
