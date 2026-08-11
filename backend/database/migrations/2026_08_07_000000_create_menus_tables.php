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
        if (!Schema::hasTable('menus')) {
            Schema::create('menus', function (Blueprint $table) {
                $table->id();
                $table->string('code', 100)->unique();
                $table->string('title_th');
                $table->string('title_en')->nullable();
                $table->string('module', 50)->default('system');
                $table->string('icon', 100)->nullable();
                $table->string('route_name', 100)->nullable();
                $table->string('route_path')->nullable();
                $table->string('component_path')->nullable();
                $table->foreignId('parent_id')->nullable()->constrained('menus')->cascadeOnDelete();
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_external')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('menu_permissions')) {
            Schema::create('menu_permissions', function (Blueprint $table) {
                $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
                $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
                $table->primary(['menu_id', 'permission_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menu_permissions');
        Schema::dropIfExists('menus');
    }
};
