<?php

namespace App\Services\Reporting;

use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Builder;

/**
 * แปลง "กลุ่มของรายงาน" (ลาป่วย/ลากิจ/ลาพักผ่อน) เป็นชุด leave_type_id
 *
 * REPORT-005 — รายงานเคยเทียบชื่อไทยของประเภทการลาใน SQL ตรง ๆ ผู้ดูแลจึงเปลี่ยน
 * ชื่อประเภทการลาไม่ได้เลยโดยที่ยอดในรายงานไม่เพี้ยน (ดู config/leave_reporting.php)
 *
 * ที่นี่คือจุดเดียวที่รู้ว่ากลุ่มไหนประกอบด้วยประเภทการลาใดบ้าง ทั้งรายงาน
 * และหน้าจอที่ต้องแยกกลุ่มเดียวกันจึงใช้คำตอบชุดเดียวกันเสมอ
 */
class LeaveReportCategoryResolver
{
    /** @var array<string,array<int,int>> cache ต่อ 1 request */
    private array $resolved = [];

    /**
     * รหัสกลุ่ม => ชื่อคอลัมน์ภาษาไทยที่ใช้แสดงในรายงาน
     *
     * @return array<string,string>
     */
    public function labels(): array
    {
        $labels = [];

        foreach ((array) config('leave_reporting.categories', []) as $key => $definition) {
            $labels[$key] = (string) ($definition['label'] ?? $key);
        }

        return $labels;
    }

    /**
     * leave_type_id ทั้งหมดที่นับเข้ากลุ่มนี้
     *
     * @return array<int,int>
     */
    public function typeIds(string $category): array
    {
        if (isset($this->resolved[$category])) {
            return $this->resolved[$category];
        }

        $definition = (array) config("leave_reporting.categories.{$category}", []);

        $codes = array_values(array_filter(array_map(
            static fn ($code) => trim((string) $code),
            (array) ($definition['codes'] ?? []),
        )));

        $keywords = array_values(array_filter(array_map(
            static fn ($keyword) => trim((string) $keyword),
            (array) ($definition['name_keywords'] ?? []),
        )));

        if ($codes === [] && $keywords === []) {
            return $this->resolved[$category] = [];
        }

        $ids = LeaveType::query()
            ->where(function (Builder $query) use ($codes, $keywords): void {
                if ($codes !== []) {
                    $query->whereIn('code_prefix', $codes);
                }

                /*
                 * ทางถอยสำหรับข้อมูลเก่า — ใช้เฉพาะประเภทการลาที่ "ยังไม่มีรหัส"
                 * ประเภทที่มีรหัสแล้วตัดสินด้วยรหัสอย่างเดียว การเปลี่ยนชื่อจึง
                 * ไม่มีผลต่อรายงานอีกต่อไป
                 */
                if ($keywords !== []) {
                    $query->orWhere(function (Builder $legacy) use ($keywords): void {
                        $legacy->where(function (Builder $missingCode): void {
                            $missingCode->whereNull('code_prefix')->orWhere('code_prefix', '');
                        })->where(function (Builder $byName) use ($keywords): void {
                            foreach ($keywords as $keyword) {
                                $byName->orWhere('name', 'like', '%'.$keyword.'%');
                            }
                        });
                    });
                }
            })
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        return $this->resolved[$category] = $ids;
    }
}
