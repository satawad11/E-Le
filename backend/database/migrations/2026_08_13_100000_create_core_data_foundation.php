<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('org_units')->restrictOnDelete();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->string('type', 80);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['parent_id', 'is_active']);
            $table->index(['effective_from', 'effective_to']);
        });

        Schema::create('department_org_unit_map', function (Blueprint $table) {
            $table->foreignId('department_id')->primary()->constrained('departments')->restrictOnDelete();
            $table->foreignId('org_unit_id')->unique()->constrained('org_units')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->foreignId('org_unit_id')->nullable()->after('department_id')->constrained('org_units')->nullOnDelete();
        });

        Schema::table('leaves', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        $rootId = DB::table('org_units')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'parent_id' => null,
            'code' => 'ORG-ROOT',
            'name' => config('app.name', 'E-La'),
            'type' => 'organization',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('departments')->orderBy('id')->each(function (object $department) use ($rootId): void {
            $orgUnitId = DB::table('org_units')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'parent_id' => $rootId,
                'code' => 'DEPT-'.str_pad((string) $department->id, 6, '0', STR_PAD_LEFT),
                'name' => $department->name,
                'type' => 'department',
                'effective_from' => now()->toDateString(),
                'effective_to' => null,
                'is_active' => (bool) ($department->is_active ?? true),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('department_org_unit_map')->insert([
                'department_id' => $department->id,
                'org_unit_id' => $orgUnitId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        DB::table('users')->whereNull('uuid')->orderBy('id')->each(fn (object $row) => DB::table('users')->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]));
        DB::table('leaves')->whereNull('uuid')->orderBy('id')->each(fn (object $row) => DB::table('leaves')->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]));
        DB::table('attendances')->whereNull('uuid')->orderBy('id')->each(fn (object $row) => DB::table('attendances')->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]));

        DB::table('users')->whereNotNull('department_id')->update([
            'org_unit_id' => DB::raw('(SELECT org_unit_id FROM department_org_unit_map WHERE department_org_unit_map.department_id = users.department_id)'),
        ]);

        Schema::create('personnel_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->restrictOnDelete();
            $table->foreignId('personnel_type_id')->nullable()->constrained('personnel_types')->restrictOnDelete();
            $table->string('position')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'effective_from', 'effective_to'], 'personnel_assignment_effective');
            $table->index(['org_unit_id', 'effective_from', 'effective_to'], 'personnel_assignment_org_effective');
        });

        DB::table('users')
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->select(['users.id', 'users.org_unit_id', 'users.personnel_type_id', 'user_profiles.position', 'user_profiles.start_date'])
            ->orderBy('users.id')
            ->each(function (object $user): void {
                DB::table('personnel_assignments')->insert([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'org_unit_id' => $user->org_unit_id,
                    'personnel_type_id' => $user->personnel_type_id,
                    'position' => $user->position,
                    'effective_from' => $user->start_date ?: now()->toDateString(),
                    'effective_to' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel_assignments');
        Schema::table('attendances', fn (Blueprint $table) => $table->dropColumn('uuid'));
        Schema::table('leaves', fn (Blueprint $table) => $table->dropColumn('uuid'));
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('org_unit_id');
            $table->dropColumn('uuid');
        });
        Schema::dropIfExists('department_org_unit_map');
        Schema::dropIfExists('org_units');
    }
};
