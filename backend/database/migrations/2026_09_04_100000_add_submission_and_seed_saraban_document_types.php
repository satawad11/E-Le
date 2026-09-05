<?php

use App\Models\Saraban\DocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INT-05 — เวลาที่เสนอหนังสือ และประเภทหนังสือมาตรฐาน
 *
 * `submitted_at` แยกจาก `updated_at` เพราะเป็น **หลักฐานว่าหนังสือเข้าสู่กระบวนการ
 * เมื่อใด** ซึ่งต่างจากเวลาที่แถวถูกแก้ล่าสุด (ที่เปลี่ยนได้จากการแก้อะไรก็ตาม)
 *
 * ประเภทหนังสือชุดตั้งต้นเป็นข้อมูลอ้างอิงที่ระบบต้องมีตั้งแต่วันแรก มิฉะนั้น
 * ผู้ใช้สร้างหนังสือไม่ได้เลยเพราะไม่มีประเภทให้เลือก — ใช้ `firstOrCreate`
 * จึงรันซ้ำได้และไม่ทับค่าที่ผู้ดูแลแก้ไว้เอง
 */
return new class extends Migration
{
    /** @var array<int,array{code:string,name:string,abbreviation:string,category:string,sort_order:int}> */
    private const TYPES = [
        ['code' => 'external', 'name' => 'หนังสือภายนอก', 'abbreviation' => 'ภน.', 'category' => DocumentType::CATEGORY_OUTGOING, 'sort_order' => 10],
        ['code' => 'internal', 'name' => 'หนังสือภายใน', 'abbreviation' => 'ภย.', 'category' => DocumentType::CATEGORY_INTERNAL, 'sort_order' => 20],
        ['code' => 'incoming', 'name' => 'หนังสือรับ', 'abbreviation' => 'รับ', 'category' => DocumentType::CATEGORY_INCOMING, 'sort_order' => 30],
        ['code' => 'order', 'name' => 'คำสั่ง', 'abbreviation' => 'คส.', 'category' => DocumentType::CATEGORY_ORDER, 'sort_order' => 40],
        ['code' => 'announcement', 'name' => 'ประกาศ', 'abbreviation' => 'ปก.', 'category' => DocumentType::CATEGORY_ANNOUNCEMENT, 'sort_order' => 50],
    ];

    public function up(): void
    {
        Schema::table('saraban_documents', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('status');
        });

        foreach (self::TYPES as $type) {
            DocumentType::firstOrCreate(['code' => $type['code']], $type);
        }
    }

    public function down(): void
    {
        Schema::table('saraban_documents', function (Blueprint $table) {
            $table->dropColumn('submitted_at');
        });

        DocumentType::query()->whereIn('code', array_column(self::TYPES, 'code'))->delete();
    }
};
