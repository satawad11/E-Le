<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ProfileController และ LineNotificationService อ้างถึง users.line_user_id และ
 * users.notification_channel มาตลอด แต่ไม่เคยมี migration สร้างคอลัมน์เหล่านี้
 * ทำให้การบันทึกโปรไฟล์ (POST /api/profile) ล้มเหลวด้วย 500 ทุกครั้ง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'line_user_id')) {
                $table->string('line_user_id')->nullable()->after('telegram_chat_id');
            }
            if (! Schema::hasColumn('users', 'notification_channel')) {
                $table->string('notification_channel', 20)->default('both')->after('line_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['line_user_id', 'notification_channel']);
        });
    }
};
