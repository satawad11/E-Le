<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R02-5 — CORE-03 Identity & Account (docs/modules/CORE-03-AUTHENTICATION.md)
 *
 * - `users.personnel_id` ผูกบัญชีกับบุคลากร (null ได้เฉพาะบัญชี `service` / break-glass)
 *   หนึ่งบุคลากรมีบัญชีได้บัญชีเดียว
 * - `mfa_credentials.secret` และ `recovery_codes` เก็บแบบเข้ารหัส
 * - `login_events` เพิ่มได้อย่างเดียว
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('personnel_id')->nullable()->unique()->after('uuid')
                ->constrained('personnel')->restrictOnDelete();
        });

        Schema::create('mfa_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20)->default('totp');
            $table->text('secret');
            $table->timestamp('confirmed_at')->nullable();
            $table->text('recovery_codes')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });

        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('username_attempted')->nullable();
            $table->string('result', 20);
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['result', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
        Schema::dropIfExists('mfa_credentials');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('personnel_id');
        });
    }
};
