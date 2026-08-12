<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            // Substitute fields
            $table->foreignId('substitute_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('substitute_status', ['Pending', 'Accepted', 'Rejected'])->nullable();
            $table->text('substitute_reason')->nullable();

            // HR verification fields
            $table->foreignId('hr_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hr_verified_at')->nullable();

            // Cancellation fields
            $table->enum('cancellation_status', ['None', 'Pending', 'Approved', 'Rejected'])->default('None');
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancellation_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->dropForeign(['substitute_id']);
            $table->dropForeign(['hr_verified_by']);
            $table->dropColumn([
                'substitute_id', 'substitute_status', 'substitute_reason',
                'hr_verified_by', 'hr_verified_at',
                'cancellation_status', 'cancellation_reason', 'cancellation_date',
            ]);
        });
    }
};
