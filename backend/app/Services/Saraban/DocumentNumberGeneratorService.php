<?php

namespace App\Services\Saraban;

use App\Models\FiscalYear;
use App\Models\Saraban\DocumentSequence;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class DocumentNumberGeneratorService
{
    /**
     * Generate an atomic running number for the given parameters.
     *
     * แถว sequence ถูก insert แบบ insertOrIgnore ก่อนเสมอ เพื่อให้ lockForUpdate()
     * มีแถวให้ล็อกจริง (การ lockForUpdate() บนแถวที่ยังไม่มีจะไม่ล็อกอะไรเลย
     * และทำให้คำขอพร้อมกันครั้งแรกของแต่ละ (ปี, ฝ่าย, ประเภท) ชน unique index)
     */
    public function generate(int $departmentId, int $typeId, int $year): int
    {
        return DB::transaction(function () use ($departmentId, $typeId, $year) {
            $key = [
                'department_id' => $departmentId,
                'document_type_id' => $typeId,
                'year_be' => $year,
            ];

            DocumentSequence::insertOrIgnore($key + [
                'running_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DocumentSequence::where($key)->lockForUpdate()->firstOrFail();

            $sequence->increment('running_number');

            return (int) $sequence->refresh()->running_number;
        });
    }

    /**
     * ปี พ.ศ. ที่ใช้รีเซ็ตเลขรันนิ่ง
     *
     * ควบคุมด้วย setting `saraban_number_reset_mode`:
     *   fiscal   = ปีงบประมาณไทย (1 ต.ค. – 30 ก.ย.) ตามตาราง fiscal_years ของระบบ Leave — ค่าเริ่มต้น
     *   calendar = ปีปฏิทิน พ.ศ.
     */
    public function resolveYearBe(): int
    {
        $mode = optional(Setting::where('key', 'saraban_number_reset_mode')->first())->value ?? 'fiscal';

        if ($mode === 'calendar') {
            return (int) date('Y') + 543;
        }

        $fiscalYear = FiscalYear::where('is_current', true)->first();

        return (int) ($fiscalYear->year ?? (date('Y') + 543));
    }

    /**
     * Format the running number to a 4-digit string.
     */
    public function format(int $number): string
    {
        return str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * ประกอบเลขที่หนังสือฉบับเต็ม เช่น "ศธ 0584.1/ว 0012/2569"
     */
    public function buildDocumentNumber(int $departmentId, int $typeId, ?string $typeCode = null, ?int $yearBe = null): string
    {
        $yearBe ??= $this->resolveYearBe();

        $formatted = $this->format($this->generate($departmentId, $typeId, $yearBe));
        $prefix = $typeCode ? $typeCode . ' ' : '';

        return "{$prefix}{$formatted}/{$yearBe}";
    }
}
