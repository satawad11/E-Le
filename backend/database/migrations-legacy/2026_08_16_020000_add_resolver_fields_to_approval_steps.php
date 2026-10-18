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
        Schema::table('approval_steps', function (Blueprint $table) {
            if (! Schema::hasColumn('approval_steps', 'approver_type')) {
                $table->string('approver_type')->default('ROLE')->after('step_order');
            }
            if (! Schema::hasColumn('approval_steps', 'specific_user_id')) {
                $table->foreignId('specific_user_id')->nullable()->after('approver_type')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('approval_steps', 'permission_name')) {
                $table->string('permission_name')->nullable()->after('specific_user_id');
            }
            // Allow role_id to be nullable if other resolver type is used
            $table->unsignedBigInteger('role_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('approval_steps', function (Blueprint $table) {
            if (Schema::hasColumn('approval_steps', 'permission_name')) {
                $table->dropColumn('permission_name');
            }
            if (Schema::hasColumn('approval_steps', 'specific_user_id')) {
                $table->dropConstrainedForeignId('specific_user_id');
            }
            if (Schema::hasColumn('approval_steps', 'approver_type')) {
                $table->dropColumn('approver_type');
            }
        });
    }
};
