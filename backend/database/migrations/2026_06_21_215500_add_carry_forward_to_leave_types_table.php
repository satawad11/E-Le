<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('is_carry_forward')->default(false)->after('max_days');
            $table->integer('max_carry_forward')->nullable()->after('is_carry_forward');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['is_carry_forward', 'max_carry_forward']);
        });
    }
};
