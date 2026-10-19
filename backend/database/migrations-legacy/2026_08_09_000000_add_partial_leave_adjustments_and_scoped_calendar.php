<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_adjustments')) {
            Schema::create('leave_adjustments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('leave_id')->constrained('leaves')->cascadeOnDelete();
                $t->foreignId('requested_by')->constrained('users');
                $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->date('cancel_from');
                $t->date('cancel_to');
                $t->decimal('days', 7, 2);
                $t->text('reason');
                $t->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
                $t->timestamp('decided_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('work_calendar_exceptions')) {
            Schema::create('work_calendar_exceptions', function (Blueprint $t) {
                $t->id();
                $t->date('date');
                $t->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
                $t->enum('day_type', ['holiday', 'working_day']);
                $t->string('name');
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['date', 'department_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_calendar_exceptions');
        Schema::dropIfExists('leave_adjustments');
    }
};
