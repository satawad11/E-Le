<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * รหัสสำหรับตรวจสอบใบลาที่พิมพ์ออกไป (QR บนเอกสาร) แบบเดียวกับที่โมดูลสารบรรณใช้อยู่
 *  - verification_hash : โทเคนสุ่มสำหรับใส่ใน URL ตรวจสอบ เดาไม่ได้
 *  - content_hash      : ลายนิ้วมือของเนื้อหาใบลา ณ เวลาอนุมัติ ใช้ยืนยันว่าข้อมูลไม่ถูกแก้ภายหลัง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->string('verification_hash', 64)->nullable()->unique()->after('document_number');
            $table->string('content_hash', 64)->nullable()->after('verification_hash');
        });

        DB::table('leaves')->whereNull('verification_hash')->orderBy('id')->chunkById(200, function ($leaves) {
            foreach ($leaves as $leave) {
                DB::table('leaves')->where('id', $leave->id)->update(['verification_hash' => Str::random(40)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->dropUnique(['verification_hash']);
            $table->dropColumn(['verification_hash', 'content_hash']);
        });
    }
};
