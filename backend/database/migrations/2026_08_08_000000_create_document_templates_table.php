<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('module')->default('leave'); // 'leave', 'attendance', 'saraban', 'hr'
            $table->string('paper_size')->default('A4');
            $table->string('orientation')->default('portrait');
            $table->decimal('margin_top', 4, 1)->default(8.0);
            $table->decimal('margin_bottom', 4, 1)->default(8.0);
            $table->decimal('margin_left', 4, 1)->default(12.0);
            $table->decimal('margin_right', 4, 1)->default(12.0);
            $table->longText('content_html');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
