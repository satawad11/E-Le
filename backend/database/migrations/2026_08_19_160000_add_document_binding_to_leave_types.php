<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ผูก "ประเภทการลา" เข้ากับ "แบบฟอร์มที่ใช้พิมพ์" และ "ฟิลด์เฉพาะของแบบฟอร์มนั้น"
 *
 * เดิมการเลือกแบบฟอร์มเทียบจากชื่อไทยของประเภทการลาตรง ๆ ในโค้ด
 * (`$type->name === 'ลาพักผ่อน'` ฯลฯ) ผู้ดูแลจึงเพิ่มหรือเปลี่ยนชื่อประเภทการลาไม่ได้
 * โดยไม่ทำให้เอกสารพิมพ์ผิดแบบฟอร์ม — ขัดกับข้อห้าม hard-code policy/แบบฟอร์ม
 *
 * - `document_template_code` เลือกแบบฟอร์มได้จากหน้าตั้งค่าประเภทการลา
 * - `extra_fields` เก็บนิยามช่องกรอกเฉพาะแบบฟอร์ม (เช่น ชื่อวัดของใบลาอุปสมบท)
 *   ค่าที่ผู้ใช้กรอกไปเก็บใน `leaves.meta_data` และนำไปเติมลงแบบฟอร์มตอนพิมพ์
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->string('document_template_code')->nullable()->after('code_prefix');
            $table->json('extra_fields')->nullable()->after('document_template_code');
        });

        // backfill ตามตรรกะเดิมที่เคยฝังอยู่ในโค้ด เพื่อให้ใบลาที่มีอยู่พิมพ์ได้เหมือนเดิม
        $rules = [
            'พักผ่อน' => 'leave_vacation',
            'ช่วยเหลือภริยา' => 'leave_paternity',
            'อุปสมบท' => 'leave_ordination',
            'ฮัจญ์' => 'leave_hajj',
            'ติดตามคู่สมรส' => 'leave_spouse',
            'ศึกษาต่อ' => 'leave_study',
            'ไปราชการ' => 'official_travel',
            'ป่วย' => 'leave_sick_personal',
            'กิจ' => 'leave_sick_personal',
            'คลอด' => 'leave_sick_personal',
        ];

        foreach (DB::table('leave_types')->select('id', 'name')->get() as $type) {
            foreach ($rules as $needle => $code) {
                if (str_contains((string) $type->name, $needle)) {
                    DB::table('leave_types')->where('id', $type->id)->update(['document_template_code' => $code]);
                    break;
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->dropColumn(['document_template_code', 'extra_fields']);
        });
    }
};
