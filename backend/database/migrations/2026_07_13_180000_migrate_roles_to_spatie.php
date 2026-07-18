<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use App\Models\User;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Sync existing data to Spatie
        // Ensure roles exist in Spatie
        $oldRoles = DB::table('legacy_roles')->get();
        foreach ($oldRoles as $oldRole) {
            Role::firstOrCreate(['name' => $oldRole->name, 'guard_name' => 'web']);
        }

        // Map users to new roles
        $users = User::all();
        foreach ($users as $user) {
            if ($user->role_id) {
                $oldRole = DB::table('legacy_roles')->where('id', $user->role_id)->first();
                if ($oldRole) {
                    $user->assignRole($oldRole->name);
                }
            }
        }

        // 2. Drop old columns and tables
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'role_id')) {
                $table->dropForeign(['role_id']);
                $table->dropColumn('role_id');
            }
            if (Schema::hasColumn('users', 'custom_permissions')) {
                $table->dropColumn('custom_permissions');
            }
        });

        if (Schema::hasTable('approval_steps')) {
            Schema::table('approval_steps', function (Blueprint $table) {
                // Ignore if dropping fails, but try to drop the foreign key
                try {
                    $table->dropForeign(['role_id']);
                } catch (\Exception $e) {}
            });
        }

        Schema::dropIfExists('legacy_roles');

        // Re-add the foreign key to point to the new roles table
        if (Schema::hasTable('approval_steps')) {
            Schema::table('approval_steps', function (Blueprint $table) {
                $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->json('custom_permissions')->nullable();
        });
    }
};
