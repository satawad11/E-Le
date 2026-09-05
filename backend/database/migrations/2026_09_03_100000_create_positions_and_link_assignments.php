<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * INT-03 · RISK-03 — ทำให้ "ตำแหน่ง" เป็นสิ่งที่อ้างอิงได้ ไม่ใช่ข้อความอิสระ
 *
 * `personnel_assignments.position` เก็บชื่อตำแหน่งเป็น **ข้อความ** ทำให้ตอบคำถามของ
 * SoT §13 (Authority Matrix) ว่า "ใครมีอำนาจอะไร ณ เวลาใด" ได้ไม่แม่น เพราะ
 * "หัวหน้างานพัสดุ" กับ "หัวหน้างานพัสดุ " (มีช่องว่างท้าย) นับเป็นคนละตำแหน่ง
 * และการเดินสายงานตามตำแหน่ง (SoT §11) ก็เทียบด้วยการจับคู่ข้อความล้วน ๆ
 *
 * แนวทางที่กระทบน้อยที่สุด — **เพิ่มอย่างเดียว ไม่ถอนของเดิม**:
 *   1. สร้างตาราง `positions` จากชื่อตำแหน่งที่มีอยู่จริงในข้อมูล
 *   2. เพิ่ม `position_id` แบบ nullable แล้วชี้ไปยังแถวที่ตรงกัน
 *   3. **คงคอลัมน์ `position` เดิมไว้ทั้งหมด** โค้ดเดิมทุกจุดจึงทำงานเหมือนเดิม
 *
 * เป็น Core extension ไม่ใช่ตารางของสารบรรณ — โมดูลอื่นใช้ประโยชน์ได้ด้วย
 * (SoT §4: ห้ามสารบรรณสร้างทะเบียนตำแหน่งของตัวเอง)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });

        Schema::table('personnel_assignments', function (Blueprint $table) {
            $table->foreignId('position_id')
                ->nullable()
                ->after('position')
                ->constrained('positions')
                ->nullOnDelete();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('personnel_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });

        Schema::dropIfExists('positions');
    }

    /**
     * สร้างแถวตำแหน่งจากชื่อที่มีอยู่จริง แล้วผูกกลับเข้า assignment
     *
     * ตัดช่องว่างหัวท้ายก่อนเทียบ เพื่อไม่ให้ชื่อที่ต่างกันแค่ช่องว่างกลายเป็นคนละตำแหน่ง
     * ชื่อว่างถือว่า "ยังไม่ระบุตำแหน่ง" — ปล่อย `position_id` เป็น null ไม่สร้างแถวขยะ
     */
    private function backfill(): void
    {
        $names = DB::table('personnel_assignments')
            ->whereNotNull('position')
            ->pluck('position')
            ->map(fn ($name) => trim((string) $name))
            ->filter(fn (string $name) => $name !== '')
            ->unique()
            ->values();

        $now = now();

        foreach ($names as $index => $name) {
            $positionId = DB::table('positions')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'code' => $this->codeFor($name, $index),
                'name' => $name,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('personnel_assignments')
                ->whereRaw('TRIM(position) = ?', [$name])
                ->update(['position_id' => $positionId]);
        }
    }

    /**
     * รหัสตำแหน่งที่อ่านออกและไม่ชนกัน
     *
     * ชื่อตำแหน่งเป็นภาษาไทย การ slug จึงมักได้ค่าว่าง — ใช้ลำดับที่กำกับไว้เสมอ
     * เพื่อให้ผ่าน unique constraint โดยไม่ต้องเดาว่า slug จะออกมาเป็นอะไร
     */
    private function codeFor(string $name, int $index): string
    {
        $slug = Str::slug($name);
        $prefix = $slug !== '' ? Str::limit($slug, 60, '') : 'position';

        return $prefix.'-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
    }
};
