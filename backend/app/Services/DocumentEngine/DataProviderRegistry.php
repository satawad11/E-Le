<?php

namespace App\Services\DocumentEngine;

use App\Models\Document\DocumentDataSource;
use Illuminate\Contracts\Container\Container;

/**
 * ทะเบียนแหล่งข้อมูลของ Document Engine
 *
 * มีเพียง provider ที่ประกาศไว้ในโค้ดตรงนี้เท่านั้นที่ถูกเรียกใช้ได้
 * ต่อให้มีแถวแปลกปลอมใน `document_data_sources` ก็เรียกไม่ได้ —
 * ตารางเป็นเพียงรายการให้ผู้ดูแล "เลือก" ไม่ใช่ที่มาของคลาสที่จะถูก instantiate
 */
class DataProviderRegistry
{
    /** @var array<string,class-string<DocumentDataProvider>> */
    private const PROVIDERS = [
        Providers\LeaveRequestDataProvider::CODE => Providers\LeaveRequestDataProvider::class,
        Providers\LeaveStatisticsDataProvider::CODE => Providers\LeaveStatisticsDataProvider::class,
        Providers\PersonnelProfileDataProvider::CODE => Providers\PersonnelProfileDataProvider::class,
    ];

    public function __construct(private readonly Container $container) {}

    /** @return array<string,class-string<DocumentDataProvider>> */
    public function all(): array
    {
        return self::PROVIDERS;
    }

    public function has(string $code): bool
    {
        return isset(self::PROVIDERS[$code]);
    }

    public function get(string $code): DocumentDataProvider
    {
        if (! $this->has($code)) {
            throw new \InvalidArgumentException("ไม่รู้จักแหล่งข้อมูล {$code} — ต้องลงทะเบียนใน DataProviderRegistry ก่อน");
        }

        return $this->container->make(self::PROVIDERS[$code]);
    }

    /** ซิงก์รายการ provider ลงตารางเพื่อให้หน้าผู้ดูแลเลือกได้ (idempotent) */
    public function sync(): void
    {
        foreach (self::PROVIDERS as $code => $class) {
            /** @var DocumentDataProvider $provider */
            $provider = $this->container->make($class);

            DocumentDataSource::updateOrCreate(
                ['code' => $code],
                [
                    'name_th' => $this->nameFor($code),
                    'module' => $provider->module(),
                    'kind' => $code === Providers\LeaveStatisticsDataProvider::CODE
                        ? DocumentDataSource::KIND_DATASET
                        : DocumentDataSource::KIND_RECORD,
                    'provider_class' => $class,
                    'is_active' => true,
                ],
            );
        }
    }

    private function nameFor(string $code): string
    {
        return match ($code) {
            Providers\LeaveRequestDataProvider::CODE => 'รายละเอียดใบลา (1 ฉบับ)',
            Providers\LeaveStatisticsDataProvider::CODE => 'สรุปสถิติการลา (หลายรายการ)',
            Providers\PersonnelProfileDataProvider::CODE => 'ข้อมูลบุคลากรและสถานศึกษา',
            default => $code,
        };
    }
}
