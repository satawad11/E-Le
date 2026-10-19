<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-05 — บัญชีและความปลอดภัย (CORE-03)
 *
 * - `users.status_reason` บอกที่มาของสถานะ (เช่น `personnel:study_leave`, `inactive_90_days`, `manual`)
 *   เพื่อให้ JML/`accounts:reconcile` คืนสถานะเฉพาะที่ตนเป็นผู้ตั้ง ไม่ทับการระงับของผู้ดูแล
 * - `users.failed_login_attempts` นับรหัสผ่าน/รหัส MFA ผิดต่อบัญชี (ล็อก 30 นาทีเมื่อครบ 5)
 * - `mfa_credentials.last_timestep` กันใช้รหัส TOTP เดิมซ้ำ
 * - `known_devices` อุปกรณ์ที่เคยเข้าใช้ (เก็บ hash ของรหัสอุปกรณ์เท่านั้น) — อุปกรณ์ใหม่ส่งอีเมลแจ้งเตือน
 * - `login_events.device_hash` ผูกเหตุการณ์กับอุปกรณ์
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status_reason', 120)->nullable()->after('status');
            $table->timestamp('status_changed_at')->nullable()->after('status_reason');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0)->after('locked_until');
        });

        Schema::table('mfa_credentials', function (Blueprint $table) {
            $table->unsignedBigInteger('last_timestep')->nullable()->after('last_used_at');
        });

        Schema::create('known_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('device_hash', 64);
            $table->string('label', 120)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('trusted_until');
            $table->timestamps();

            $table->unique(['user_id', 'device_hash']);
        });

        Schema::table('login_events', function (Blueprint $table) {
            $table->string('device_hash', 64)->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('login_events', function (Blueprint $table) {
            $table->dropColumn('device_hash');
        });

        Schema::dropIfExists('known_devices');

        Schema::table('mfa_credentials', function (Blueprint $table) {
            $table->dropColumn('last_timestep');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status_reason', 'status_changed_at', 'failed_login_attempts']);
        });
    }
};
