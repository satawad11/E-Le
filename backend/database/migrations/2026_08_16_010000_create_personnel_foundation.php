<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('personnel', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('personnel_code', 80)->nullable()->index();
            $table->string('title', 50)->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('status', 50)->default('active');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['status', 'effective_from', 'effective_to'], 'personnel_status_effective_idx');
        });

        // Idempotent backfill from existing users + user_profiles
        DB::table('users')
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->select([
                'users.id as user_id',
                'users.name as user_name',
                'users.is_active as user_is_active',
                'users.created_at as user_created_at',
                'user_profiles.personnel_code',
                'user_profiles.title',
                'user_profiles.first_name',
                'user_profiles.last_name',
                'user_profiles.start_date',
                'user_profiles.status as profile_status',
            ])
            ->orderBy('users.id')
            ->each(function (object $user): void {
                $alreadyExists = DB::table('personnel')->where('user_id', $user->user_id)->exists();
                if ($alreadyExists) {
                    return;
                }

                $firstName = trim((string) ($user->first_name ?? ''));
                $lastName = trim((string) ($user->last_name ?? ''));

                if ($firstName === '') {
                    $parts = explode(' ', trim((string) $user->user_name), 2);
                    $firstName = $parts[0] !== '' ? $parts[0] : 'User_'.$user->user_id;
                    $lastName = $parts[1] ?? '';
                }

                $effectiveFrom = $user->start_date ?: ($user->user_created_at ? substr((string) $user->user_created_at, 0, 10) : now()->toDateString());
                $status = $user->user_is_active ? ($user->profile_status ?: 'active') : 'inactive';

                DB::table('personnel')->insert([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $user->user_id,
                    'personnel_code' => $user->personnel_code ?: null,
                    'title' => $user->title ?: null,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'status' => $status,
                    'effective_from' => $effectiveFrom,
                    'effective_to' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personnel');
    }
};
