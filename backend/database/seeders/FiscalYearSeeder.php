<?php

namespace Database\Seeders;

use App\Models\FiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * สร้างข้อมูลปีงบประมาณไทย
 *
 * ตาราง fiscal_years ว่างเปล่ามาตั้งแต่ต้น ทำให้ FiscalYear::current() คืน null
 * ซึ่งกระทบการคำนวณสิทธิ์วันลาและการออกเลขที่หนังสือ (พบตอน LEGACY-00)
 *
 * ปีงบประมาณไทยเป็น "มาตรฐานระดับประเทศ" ไม่ใช่ข้อมูลเฉพาะของสถานศึกษา
 * คือ 1 ตุลาคม ถึง 30 กันยายน และเรียกชื่อตามปี พ.ศ. ที่สิ้นสุด
 * เช่น ปีงบประมาณ 2569 = 1 ต.ค. 2568 ถึง 30 ก.ย. 2569
 *
 * จึงสร้างข้อมูลนี้ได้โดยไม่ต้องรอการรับรองจากองค์กร ต่างจากวันหยุดประจำปี
 * และแม่แบบเอกสารซึ่งเป็นข้อมูลเฉพาะหน่วยงาน — สองอย่างนั้นยังคงว่างไว้โดยตั้งใจ
 *
 * seeder นี้ idempotent รันซ้ำได้ ไม่สร้างข้อมูลซ้ำ และไม่ทับค่าที่ผู้ดูแลแก้ไว้เอง
 * นอกจากช่วงวันที่ซึ่งอิงมาตรฐาน
 */
class FiscalYearSeeder extends Seeder
{
    /** จำนวนปีย้อนหลังและล่วงหน้าที่สร้างไว้รองรับ */
    private const YEARS_BACK = 2;

    private const YEARS_FORWARD = 2;

    public function run(): void
    {
        $today = CarbonImmutable::today();
        $currentYearBe = $this->fiscalYearFor($today);

        $from = $currentYearBe - self::YEARS_BACK;
        $to = $currentYearBe + self::YEARS_FORWARD;

        DB::transaction(function () use ($from, $to, $currentYearBe) {
            for ($yearBe = $from; $yearBe <= $to; $yearBe++) {
                $endCe = $yearBe - 543;

                FiscalYear::updateOrCreate(
                    ['year' => $yearBe],
                    [
                        'name' => "ปีงบประมาณ {$yearBe}",
                        'start_date' => CarbonImmutable::create($endCe - 1, 10, 1)->toDateString(),
                        'end_date' => CarbonImmutable::create($endCe, 9, 30)->toDateString(),
                        'is_current' => $yearBe === $currentYearBe,
                    ]
                );
            }

            // กันกรณีมีแถวเก่าที่ถูกทำเครื่องหมาย current ค้างไว้ — ต้องมีปีปัจจุบันเดียว
            FiscalYear::where('year', '!=', $currentYearBe)
                ->where('is_current', true)
                ->update(['is_current' => false]);
        });

        $this->command?->info("สร้าง/ปรับปรุงปีงบประมาณ {$from}–{$to} · ปีปัจจุบัน {$currentYearBe}");
    }

    /** ปีงบประมาณ (พ.ศ.) ของวันที่ที่กำหนด — ขึ้นปีใหม่ทุก 1 ตุลาคม */
    private function fiscalYearFor(CarbonImmutable $date): int
    {
        $yearBe = $date->year + 543;

        return $date->month >= 10 ? $yearBe + 1 : $yearBe;
    }
}
