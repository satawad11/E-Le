<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->string('name');           // e.g. "ปีงบประมาณ 2567"
            $table->integer('year');           // e.g. 2567 (พ.ศ.)
            $table->date('start_date');        // e.g. 2023-10-01
            $table->date('end_date');          // e.g. 2024-09-30
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
