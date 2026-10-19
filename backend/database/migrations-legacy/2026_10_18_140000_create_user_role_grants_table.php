<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCESS-GOV-01 · C3 — ทะเบียนการมอบบทบาท
 *
 * Spatie เก็บแค่ "ตอนนี้มีบทบาทอะไร" ไม่รู้ว่าใครให้ ตามคำสั่งใด และหมดเมื่อไร
 * ตารางนี้เก็บทุกการมอบบทบาทเป็นแถวที่ปิดได้แต่ไม่ลบ บทบาทที่ตั้งวันหมดอายุไว้
 * จะถูกถอนกลับเป็นบทบาทพื้นฐานโดย `access:expire-role-grants` (รันทุกวัน)
 *
 * role_name เก็บชื่อ ณ ตอนมอบ เพื่อให้ประวัติอ่านได้แม้บทบาทถูกเปลี่ยนชื่อหรือลบภายหลัง
 * additive เท่านั้น
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_role_grants')) {
            return;
        }

        Schema::create('user_role_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('role_name');
            $table->string('order_number', 100)->nullable();
            $table->date('effective_from');
            $table->date('expires_at')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('end_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'ended_at'], 'user_role_grants_open_index');
            $table->index(['expires_at', 'ended_at'], 'user_role_grants_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_grants');
    }
};
