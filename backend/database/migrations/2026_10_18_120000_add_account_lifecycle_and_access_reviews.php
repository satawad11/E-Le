<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCESS-GOV-01 — วงจรบัญชีผู้ใช้และการทบทวนสิทธิ์รายปีการศึกษา
 *
 * - `users.separated_at` / `separation_reason`: บุคลากรพ้นสภาพ (ย้าย ลาออก เกษียณ หมดสัญญา)
 *   บัญชีไม่ถูกลบ (ประวัติการลา/ลงเวลายังอ้างถึง) แต่ระงับและปิดอำนาจทั้งหมด ณ วันพ้นสภาพ
 * - `users.last_login_at`: ใช้ในรายงานทบทวนสิทธิ์ เพื่อหาบัญชีที่ไม่ได้ใช้งาน
 * - `access_reviews`: รอบทบทวนสิทธิ์ปีละครั้งตามปีการศึกษา (เจ้าของระบบตอบ Q9)
 *
 * additive เท่านั้น
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'separated_at')) {
                $table->date('separated_at')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('users', 'separation_reason')) {
                $table->string('separation_reason', 255)->nullable()->after('separated_at');
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('separation_reason');
            }
        });

        if (! Schema::hasTable('access_reviews')) {
            Schema::create('access_reviews', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                // ปีการศึกษา พ.ศ. เช่น 2569 — หนึ่งรอบต่อปี
                $table->string('academic_year', 4)->unique();
                $table->timestamp('generated_at');
                $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('user_count')->default(0);
                $table->unsignedInteger('privileged_count')->default(0);
                $table->string('file_path')->nullable();
                $table->foreignId('signed_off_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('signed_off_at')->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('access_reviews');

        Schema::table('users', function (Blueprint $table) {
            foreach (['last_login_at', 'separation_reason', 'separated_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
