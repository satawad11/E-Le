<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saraban_document_types', function (Blueprint $table) {
            $table->string('color')->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('saraban_document_types', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
