<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ใบลงเวลารายเดือนต้องแยกประเภทการลาจากค่าที่กำหนดไว้ ไม่ใช่การค้นคำในชื่อไทย
 *
 * เดิมสรุปรายเดือนตัดสินช่อง ป/ก/ด/ร ด้วย `str_contains` บนชื่อประเภทการลา
 * แล้วมีทางออกสุดท้ายว่า "อะไรที่ไม่เข้าเงื่อนไข ให้นับเป็นลากิจ" ผลคือ
 *   - ลาพักผ่อน ตกไปช่องลากิจ
 *   - ลาไปช่วยเหลือภริยาที่คลอดบุตร มีคำว่า "คลอด" จึงถูกนับเป็นลาคลอดบุตร
 * ยอดรวมท้ายใบลงเวลาที่ส่งต่องานบุคคลจึงผิดตั้งแต่ต้นทาง
 *
 * ค่าตั้งต้นผูกจาก `code_prefix` ซึ่งเป็นรหัสประจำประเภทที่ระบบใช้ออกเลขที่หนังสืออยู่แล้ว
 * ไม่ใช่ชื่อที่ผู้ดูแลแก้ได้ตลอดเวลา · ประเภทที่เพิ่มเองภายหลังเป็น null = ช่อง "อื่น ๆ"
 * และผู้ดูแลกำหนดเองได้ที่หน้าจัดการประเภทการลา
 */
return new class extends Migration
{
    /** code_prefix => ช่องในใบลงเวลา */
    private const DEFAULTS = [
        'ลป.' => 'sick',       // ลาป่วย
        'ลค.' => 'maternity',  // ลาคลอดบุตร
        'ลช.' => 'personal',   // ลาไปช่วยเหลือภริยาที่คลอดบุตร — สิทธิ์ของสามี ไม่ใช่ลาคลอด
        'ลก.' => 'personal',   // ลากิจส่วนตัว
        'ลพ.' => 'vacation',   // ลาพักผ่อน — เดิมถูกนับรวมเป็นลากิจ
        'ลอ.' => 'other',      // ลาอุปสมบท
        'ลฮ.' => 'other',      // ลาไปประกอบพิธีฮัจญ์
        'ลท.' => 'official',   // ลาเข้ารับการตรวจเลือก/เตรียมพล — ราชการเรียกตัว
        'ลศ.' => 'other',      // ลาไปศึกษาต่อ ดูงาน ฝึกอบรม
        'ลร.' => 'official',   // ลาไปปฏิบัติงานในองค์การระหว่างประเทศ
        'ลต.' => 'other',      // ลาติดตามคู่สมรส
        'ลฟ.' => 'other',      // ลาไปฟื้นฟูสมรรถภาพด้านอาชีพ
    ];

    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->string('attendance_bucket', 20)->nullable()->after('counts_toward_promotion');
        });

        foreach (self::DEFAULTS as $prefix => $bucket) {
            DB::table('leave_types')->where('code_prefix', $prefix)->update(['attendance_bucket' => $bucket]);
        }
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('attendance_bucket');
        });
    }
};
