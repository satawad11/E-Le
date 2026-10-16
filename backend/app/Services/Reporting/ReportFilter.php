<?php

namespace App\Services\Reporting;

use App\Models\FiscalYear;
use App\Models\PositionGroup;
use Carbon\CarbonImmutable;

final readonly class ReportFilter
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public int $fiscalYear,
        public ?int $orgUnitId = null,
        public ?int $userId = null,
        public ?int $personnelTypeId = null,
        // ORG-ACCESS-02: กลุ่มบุคลากร (ผู้บริหาร / ครู / เจ้าหน้าที่) ตามตำแหน่งปัจจุบันในทะเบียนตำแหน่ง
        public ?int $positionGroupId = null,
    ) {}

    public static function from(array $input): self
    {
        $fiscal = isset($input['fiscal_year_id'])
            ? FiscalYear::query()->find($input['fiscal_year_id'])
            : (isset($input['fiscal_year'])
                ? FiscalYear::query()->where('year', $input['fiscal_year'])->first()
                : FiscalYear::current());

        $year = (int) ($input['fiscal_year'] ?? $fiscal?->year ?? self::currentFiscalYear());
        $from = isset($input['from'])
            ? CarbonImmutable::parse($input['from'])->startOfDay()
            : CarbonImmutable::parse($fiscal?->start_date ?? ($year - 1).'-10-01')->startOfDay();
        $to = isset($input['to'])
            ? CarbonImmutable::parse($input['to'])->endOfDay()
            : CarbonImmutable::parse($fiscal?->end_date ?? "{$year}-09-30")->endOfDay();

        return new self(
            $from,
            $to,
            $year,
            isset($input['org_unit_id']) ? (int) $input['org_unit_id'] : null,
            isset($input['user_id']) ? (int) $input['user_id'] : null,
            isset($input['personnel_type_id']) ? (int) $input['personnel_type_id'] : null,
            self::positionGroupId($input['position_group'] ?? null),
        );
    }

    public function meta(): array
    {
        return [
            'fiscal_year' => $this->fiscalYear,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'org_unit_id' => $this->orgUnitId,
            'user_id' => $this->userId,
            'personnel_type_id' => $this->personnelTypeId,
            'position_group_id' => $this->positionGroupId,
        ];
    }

    /**
     * รับ UUID ของกลุ่ม (ไม่รับ id ภายใน) — UUID ที่ไม่มีอยู่จริงกรองได้ผลว่าง ไม่ใช่ไม่กรอง
     */
    private static function positionGroupId(mixed $uuid): ?int
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return (int) (PositionGroup::query()->where('uuid', $uuid)->value('id') ?? 0);
    }

    private static function currentFiscalYear(): int
    {
        $today = CarbonImmutable::today();

        return $today->month >= 10 ? $today->year + 1 : $today->year;
    }
}
