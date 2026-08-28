<?php

namespace App\Services\NotificationCenter;

use App\Contracts\NotificationCenter\NotificationChannelAdapter;

/**
 * ทะเบียนช่องทางส่งของ Notification Center
 *
 * CLEANUP-01 (D8) — เดิม map ช่องทางถูกประกอบไว้ใน `NotificationDeliveryService`
 * เท่านั้น ใครที่อยากส่งผ่านช่องทางเดียวกัน (เช่น ปุ่มทดสอบในหน้าตั้งค่า)
 * จึงเรียก provider ตรง ๆ แล้วข้ามทั้ง `isConfiguredFor` และการจัดการข้อผิดพลาด
 * แยกทะเบียนออกมาเพื่อให้ทุกทางเดินผ่าน adapter ชุดเดียวกัน
 */
class ChannelAdapterRegistry
{
    /** ชื่อช่องทางที่หน้าเว็บและ API เดิมใช้ → ชื่อช่องทางจริง */
    private const ALIASES = ['line' => 'line_oa'];

    /** @param array<string, NotificationChannelAdapter> $adapters */
    public function __construct(private readonly array $adapters) {}

    /** @param iterable<NotificationChannelAdapter> $adapters */
    public static function of(iterable $adapters): self
    {
        $map = [];

        foreach ($adapters as $adapter) {
            $map[$adapter->channel()] = $adapter;
        }

        return new self($map);
    }

    public function for(string $channel): ?NotificationChannelAdapter
    {
        return $this->adapters[self::ALIASES[$channel] ?? $channel] ?? null;
    }

    public function has(string $channel): bool
    {
        return $this->for($channel) !== null;
    }

    /** @return array<string, NotificationChannelAdapter> */
    public function all(): array
    {
        return $this->adapters;
    }
}
