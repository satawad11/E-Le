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
        Schema::table('attendances', function (Blueprint $table) {
            $table->time('ot_check_in_time')->nullable();
            $table->time('ot_check_out_time')->nullable();
            $table->integer('late_minutes')->nullable()->default(0);
            $table->integer('early_leave_minutes')->nullable()->default(0);
            $table->integer('ot_minutes')->nullable()->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['ot_check_in_time', 'ot_check_out_time', 'late_minutes', 'early_leave_minutes', 'ot_minutes']);
        });
    }
};
