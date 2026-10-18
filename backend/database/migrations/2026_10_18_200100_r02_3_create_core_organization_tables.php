<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R02-3 — CORE-01 Organization (docs/modules/CORE-01-ORGANIZATION.md)
 *
 * ระดับ L1–L4 มาจาก `org_unit_types.level` ไม่ใช่ความลึกของต้นไม้ (ADR-R-009)
 * `org_units.path` เป็น materialized path (`/1/4/17/`) ตัวอักษรมีแค่ตัวเลขกับ `/`
 * จึงเก็บเป็น ascii เพื่อให้ index เต็มความยาว 1000 ได้บน MySQL (utf8mb4 จะเกิน 3072 bytes)
 *
 * กฎที่ตรวจใน service (R-03): level ลูก > level parent · ห้ามวงวน · root active ได้แถวเดียว
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_unit_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->unsignedTinyInteger('level');
            $table->string('head_title', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['level', 'is_active']);
        });

        Schema::create('org_units', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('org_units')->restrictOnDelete();
            $table->foreignId('org_unit_type_id')->constrained('org_unit_types')->restrictOnDelete();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->string('path', 1000)->charset('ascii')->default('/');
            $table->unsignedTinyInteger('depth')->default(0);
            $table->integer('sort_order')->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index('path');
            $table->index(['parent_id', 'sort_order']);
            $table->index(['effective_from', 'effective_to']);
        });

        Schema::create('position_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('kind', 20)->default('line');
            $table->foreignId('position_group_id')->nullable()->constrained('position_groups')->restrictOnDelete();
            // ตำแหน่งบริหาร (kind = management) ผูกกับประเภทหน่วยได้ประเภทละหนึ่งตำแหน่ง
            $table->foreignId('org_unit_type_id')->nullable()->unique()->constrained('org_unit_types')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['kind', 'is_active']);
            $table->index(['position_group_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
        Schema::dropIfExists('position_groups');
        Schema::dropIfExists('org_units');
        Schema::dropIfExists('org_unit_types');
    }
};
