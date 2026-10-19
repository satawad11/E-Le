<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ot_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('order_number')->nullable()->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->string('timezone', 64)->default('Asia/Bangkok');
            $table->timestamp('scheduled_start');
            $table->timestamp('scheduled_end');
            $table->timestamp('approved_start')->nullable();
            $table->timestamp('approved_end')->nullable();
            $table->string('status', 30)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['org_unit_id', 'status']);
            $table->index(['scheduled_start', 'scheduled_end']);
        });

        Schema::create('ot_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ot_order_id')->constrained('ot_orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('assigned');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();
            $table->unique(['ot_order_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('ot_time_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ot_assignment_id')->constrained('ot_assignments')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
            $table->timestamp('actual_start')->nullable();
            $table->timestamp('actual_end')->nullable();
            $table->unsignedInteger('actual_minutes')->nullable();
            $table->timestamp('certified_start')->nullable();
            $table->timestamp('certified_end')->nullable();
            $table->unsignedInteger('certified_minutes')->nullable();
            $table->string('status', 30)->default('not_started');
            $table->string('source', 30)->default('web');
            $table->text('notes')->nullable();
            $table->foreignId('certified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('certified_at')->nullable();
            $table->string('reconciliation_status', 30)->default('pending');
            $table->json('reconciliation_details')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->unsignedBigInteger('legacy_attendance_id')->nullable()->unique();
            $table->timestamps();
            $table->unique('ot_assignment_id');
            $table->index(['user_id', 'status']);
            $table->index(['actual_start', 'actual_end']);
        });

        $this->backfillLegacyAttendanceOvertime();
    }

    private function backfillLegacyAttendanceOvertime(): void
    {
        DB::table('attendances')
            ->where(fn ($query) => $query->whereNotNull('ot_check_in_time')->orWhereNotNull('ot_check_out_time'))
            ->orderBy('id')
            ->each(function (object $attendance): void {
                $date = CarbonImmutable::parse((string) $attendance->date, 'Asia/Bangkok')->startOfDay();
                $actualStart = $this->combine($date, $attendance->ot_check_in_time);
                $actualEnd = $this->combine($date, $attendance->ot_check_out_time);
                if ($actualStart && $actualEnd && $actualEnd->lt($actualStart)) {
                    $actualEnd = $actualEnd->addDay();
                }

                $now = now();
                $orderId = DB::table('ot_orders')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'order_number' => 'LEGACY-ATT-'.$attendance->id,
                    'title' => 'ข้อมูล OT เดิม วันที่ '.$date->toDateString(),
                    'description' => 'Backfill จาก attendances โดยไม่สมมติเวลาที่อนุมัติหรือรับรอง',
                    'timezone' => 'Asia/Bangkok',
                    'scheduled_start' => ($actualStart ?? $date)->toDateTimeString(),
                    'scheduled_end' => ($actualEnd ?? $actualStart ?? $date)->toDateTimeString(),
                    'status' => 'migrated',
                    'created_by' => $attendance->user_id,
                    'submitted_at' => $attendance->created_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $assignmentId = DB::table('ot_assignments')->insertGetId([
                    'uuid' => (string) Str::uuid(), 'ot_order_id' => $orderId,
                    'user_id' => $attendance->user_id, 'status' => 'completed',
                    'assigned_at' => $attendance->created_at, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $minutes = $actualStart && $actualEnd ? (int) $actualStart->diffInMinutes($actualEnd) : null;
                DB::table('ot_time_records')->insert([
                    'uuid' => (string) Str::uuid(), 'ot_assignment_id' => $assignmentId,
                    'user_id' => $attendance->user_id, 'attendance_id' => $attendance->id,
                    'actual_start' => $actualStart?->toDateTimeString(), 'actual_end' => $actualEnd?->toDateTimeString(),
                    'actual_minutes' => $minutes, 'status' => $actualEnd ? 'recorded' : 'in_progress',
                    'source' => 'legacy', 'reconciliation_status' => 'legacy_backfill',
                    'reconciliation_details' => json_encode(['legacy_ot_minutes' => $attendance->ot_minutes]),
                    'reconciled_at' => $now, 'legacy_attendance_id' => $attendance->id,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            });
    }

    private function combine(CarbonImmutable $date, mixed $time): ?CarbonImmutable
    {
        if (blank($time)) {
            return null;
        }

        return CarbonImmutable::parse($date->toDateString().' '.substr((string) $time, 0, 8), 'Asia/Bangkok');
    }

    public function down(): void
    {
        Schema::dropIfExists('ot_time_records');
        Schema::dropIfExists('ot_assignments');
        Schema::dropIfExists('ot_orders');
    }
};
