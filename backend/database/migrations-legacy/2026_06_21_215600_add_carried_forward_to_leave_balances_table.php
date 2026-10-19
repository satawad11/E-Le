<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->float('carried_forward')->default(0)->after('quota');
        });
    }

    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->dropColumn('carried_forward');
        });
    }
};
