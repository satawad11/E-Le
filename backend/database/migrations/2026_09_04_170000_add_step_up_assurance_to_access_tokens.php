<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-GAP-01 (INT-11) — บันทึกว่า "ยืนยันตัวตนแข็งครั้งล่าสุดเมื่อใด" (SoT §24)
 *
 * การกระทำที่อ่อนไหว (ลงนาม, เพิกถอนเลขที่ออกแล้ว, เผยแพร่นโยบาย) ต้องเกิดขึ้น
 * ในช่วงเวลาที่ผู้ใช้ "เพิ่งยืนยันตัวตน" ไม่ใช่แค่ "ยังมีโทเคนที่ไม่หมดอายุ" —
 * เพราะโทเคนอยู่ได้หลายชั่วโมง เครื่องที่เปิดค้างไว้จึงลงนามแทนเจ้าของได้
 *
 * **เป็นความสามารถของ Core Identity ไม่ใช่ของสารบรรณ** ตาม SoT §4 — ทุกโมดูล
 * ที่มีการกระทำอ่อนไหวในอนาคตใช้กลไกเดียวกันได้ทันที
 *
 * **เพิ่มคอลัมน์เดียวแบบ nullable** — โทเคนที่มีอยู่แล้วยังใช้งานได้ตามปกติ
 * เพียงแต่ยังไม่มีเวลายืนยันตัวตน จึงต้องยืนยันก่อนทำสิ่งที่อ่อนไหวครั้งแรก
 * ซึ่งเป็นพฤติกรรมที่ถูกต้องอยู่แล้ว (fail closed)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestamp('last_authenticated_at')->nullable()->after('last_used_at');
        });

        /*
         * โทเคนที่ออกไปแล้วถือว่า "ยืนยันตัวตนตอนสร้าง" — ใช้ `created_at` เป็นค่าตั้งต้น
         * ไม่ใช่ปล่อยเป็น null เพราะผู้ใช้ที่เพิ่งล็อกอินเมื่อสองนาทีที่แล้วไม่ควร
         * ถูกบังคับยืนยันซ้ำทันทีเพียงเพราะระบบเพิ่งอัปเกรด
         */
        DB::table('personal_access_tokens')->update(['last_authenticated_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('last_authenticated_at');
        });
    }
};
