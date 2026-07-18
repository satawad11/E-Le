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
        Schema::table('leave_approvals', function (Blueprint $table) {
            $table->string('approver_name')->nullable()->after('approver_id');
            $table->string('approver_position')->nullable()->after('approver_name');
            $table->string('signature_path')->nullable()->after('approver_position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_approvals', function (Blueprint $table) {
            $table->dropColumn(['approver_name', 'approver_position', 'signature_path']);
        });
    }
};
