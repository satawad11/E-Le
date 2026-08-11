<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ระเบียบว่าด้วยการเลื่อนเงินเดือน: การนับวันลาเพื่อพิจารณาเลื่อนเงินเดือน
 * "ไม่รวม" การลาบางประเภท เช่น ลาอุปสมบท ลาฮัจญ์ ลาคลอดบุตร ลาพักผ่อน
 * ลาเข้ารับการตรวจเลือก/เตรียมพล และลาไปปฏิบัติงานในองค์การระหว่างประเทศ
 *
 * ตั้งค่าเริ่มต้นตามระเบียบ แต่ให้งานบุคลากรปรับได้เองที่หน้าจัดการประเภทการลา
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('counts_toward_promotion')->default(true)->after('description');
        });

        $excludedKeywords = ['อุปสมบท', 'ฮัจ', 'คลอดบุตร', 'พักผ่อน', 'ตรวจเลือก', 'เตรียมพล', 'องค์การระหว่างประเทศ'];

        foreach (DB::table('leave_types')->get() as $type) {
            // "ลาไปช่วยเหลือภริยาที่คลอดบุตร" ยังนับ — ยกเว้นเฉพาะการลาคลอดของผู้คลอดเอง
            $isPaternity = str_contains($type->name, 'ช่วยเหลือภริยา');
            $excluded = !$isPaternity && collect($excludedKeywords)->contains(fn ($kw) => str_contains($type->name, $kw));

            if ($excluded) {
                DB::table('leave_types')->where('id', $type->id)->update(['counts_toward_promotion' => false]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('counts_toward_promotion');
        });
    }
};
