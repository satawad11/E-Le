<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ขยายคอลัมน์จำนวนวันใน leave_balances จาก decimal(5,2) (สูงสุด 999.99)
 * เป็น decimal(7,2) ให้เท่ากับคอลัมน์ reserved ที่กว้างอยู่แล้ว
 *
 * เหตุ: leave_types.max_days มีค่าถึง 1460 วัน (ลาติดตามคู่สมรส 4 ปี ตามระเบียบ)
 * ซึ่งเกินเพดานเดิม — บน SQLite ไม่ตรวจช่วงค่าจึงผ่านเงียบ ๆ แต่บน MySQL
 * ตกด้วย SQLSTATE[22003] Out of range ตั้งแต่ตอน seed
 *
 * เป็นการขยายความกว้างอย่างเดียว ค่าที่มีอยู่เดิมไม่เปลี่ยนและไม่สูญหาย
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->decimal('quota', 7, 2)->default(0)->change();
            $table->decimal('used', 7, 2)->default(0)->change();
            $table->decimal('carry_forward_days', 7, 2)->default(0)->comment('Days accumulated from previous years')->change();
        });
    }

    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->decimal('quota', 5, 2)->default(0)->change();
            $table->decimal('used', 5, 2)->default(0)->change();
            $table->decimal('carry_forward_days', 5, 2)->default(0)->comment('Days accumulated from previous years')->change();
        });
    }
};
