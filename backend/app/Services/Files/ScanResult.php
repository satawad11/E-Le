<?php

namespace App\Services\Files;

/**
 * ผลการตรวจไวรัสของไฟล์หนึ่งชิ้น (SEC-GAP-02)
 *
 * แยก "ติดไวรัส" ออกจาก "ตรวจไม่ได้" อย่างชัดเจน เพราะสองกรณีนี้ต้องบอกผู้ใช้
 * คนละแบบ (ไฟล์มีปัญหา vs ระบบยังไม่พร้อม) แต่ **ทั้งสองกรณีต้องไม่ปล่อยไฟล์ผ่าน**
 */
final readonly class ScanResult
{
    private function __construct(
        public bool $clean,
        public bool $available,
        public ?string $signature = null,
        public ?string $reason = null,
    ) {}

    public static function clean(): self
    {
        return new self(clean: true, available: true);
    }

    public static function infected(string $signature): self
    {
        return new self(clean: false, available: true, signature: $signature);
    }

    /** ตรวจไม่ได้ เช่น ตัวตรวจไม่ตอบ หรือเชื่อมต่อไม่ได้ */
    public static function unavailable(string $reason): self
    {
        return new self(clean: false, available: false, reason: $reason);
    }
}
