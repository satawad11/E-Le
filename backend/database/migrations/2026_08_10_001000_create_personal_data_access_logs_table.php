<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * บันทึกการเข้าถึงข้อมูลส่วนบุคคลของผู้อื่น เพื่อให้ตอบได้ว่าใครดูข้อมูลของใครเมื่อใด
 * ตามหลักความรับผิดชอบของผู้ควบคุมข้อมูลส่วนบุคคล (พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล ม.37)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_data_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('users')->cascadeOnDelete();
            $table->string('resource', 80);   // เช่น leave_card, user_profile
            $table->string('purpose', 160)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_data_access_logs');
    }
};
