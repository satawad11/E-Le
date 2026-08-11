<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * audit log ต้องบันทึกได้แม้ผู้กระทำเป็น guest (เช่น การ verify QR สาธารณะ)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saraban_audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('saraban_audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
