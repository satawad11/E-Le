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
        Schema::table('leaves', function (Blueprint $table) {
            $table->enum('leave_period', ['full_day', 'morning', 'afternoon'])
                  ->default('full_day')
                  ->after('end_date')
                  ->comment('Period of leave: full_day, morning, afternoon');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->dropColumn('leave_period');
        });
    }
};
