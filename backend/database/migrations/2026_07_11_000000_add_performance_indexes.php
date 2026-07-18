<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * เพิ่ม indexes ที่ขาดหายเพื่อเพิ่มประสิทธิภาพการ query
     * หมายเหตุ: leaves indexes และ attendances indexes ถูก apply แล้ว
     * migration นี้ใช้ ifNotExists เพื่อความปลอดภัย
     */
    public function up(): void
    {
        // leaves table — indexes ที่ยังขาด (user_id, leave_type_id, composite, end_date)
        Schema::table('leaves', function (Blueprint $table) {
            if (!Schema::hasIndex('leaves', 'leaves_user_id_idx')) {
                $table->index('user_id', 'leaves_user_id_idx');
            }
            if (!Schema::hasIndex('leaves', 'leaves_leave_type_id_idx')) {
                $table->index('leave_type_id', 'leaves_leave_type_id_idx');
            }
            if (!Schema::hasIndex('leaves', 'leaves_user_status_idx')) {
                $table->index(['user_id', 'status'], 'leaves_user_status_idx');
            }
            if (!Schema::hasIndex('leaves', 'leaves_end_date_idx')) {
                $table->index('end_date', 'leaves_end_date_idx');
            }
        });

        // attendances table — indexes ที่ยังขาด (date, composite user+date)
        Schema::table('attendances', function (Blueprint $table) {
            if (!Schema::hasIndex('attendances', 'attendances_date_idx')) {
                $table->index('date', 'attendances_date_idx');
            }
            if (!Schema::hasIndex('attendances', 'attendances_user_date_idx')) {
                $table->index(['user_id', 'date'], 'attendances_user_date_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->dropIndexIfExists('leaves_user_id_idx');
            $table->dropIndexIfExists('leaves_leave_type_id_idx');
            $table->dropIndexIfExists('leaves_user_status_idx');
            $table->dropIndexIfExists('leaves_end_date_idx');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndexIfExists('attendances_date_idx');
            $table->dropIndexIfExists('attendances_user_date_idx');
        });
    }
};
