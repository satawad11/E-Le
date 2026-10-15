<?php

namespace App\Support;

use LogicException;

/**
 * ด่านตรวจความปลอดภัยของค่า environment ก่อนระบบเริ่มทำงาน
 *
 * SEC-022 — เดิมด่านนี้ตรวจเพียง `APP_DEBUG` ตอน `AppServiceProvider::boot()`
 * ซึ่งมีผลเป็น 500 รายคำขอเท่านั้น ตัว container ยังขึ้นปกติ migrate ปกติ
 * และดูเหมือน deploy สำเร็จ ทั้งที่ตั้งค่าไว้ไม่ปลอดภัย
 *
 * ตอนนี้:
 *  - `problems()` คืนรายการปัญหาทั้งหมด (ไทย) ไม่หยุดที่ข้อแรก
 *  - `validate()` ยังโยน LogicException เหมือนเดิมสำหรับ runtime
 *  - `php artisan app:verify-environment` เรียกด่านนี้ตอน container เริ่มทำงาน
 *    และคืน exit code ไม่ใช่ 0 ทำให้ container หยุดตั้งแต่ต้น (ดู docker-compose.yml)
 */
final class ProductionEnvironmentGuard
{
    /** ค่า APP_ENV ที่ระบบรู้จัก — ค่าอื่นถือว่าไม่ปลอดภัยเพราะมักเป็นการพิมพ์ผิด */
    public const KNOWN_ENVIRONMENTS = ['local', 'testing', 'staging', 'production'];

    /**
     * ตรวจทุกข้อและคืนรายการปัญหาที่พบ (ว่าง = ปลอดภัย)
     *
     * @param  array{app_key?: string|null, serve_local_disk?: bool, app_url?: string|null}  $context
     * @return array<int,string>
     */
    public static function problems(string $environment, bool $debug, array $context = []): array
    {
        $problems = [];
        $isProduction = $environment === 'production';

        if ($isProduction && $debug) {
            $problems[] = 'APP_DEBUG ต้องเป็น false เมื่อ APP_ENV เป็น production';
        }

        if (! in_array($environment, self::KNOWN_ENVIRONMENTS, true)) {
            $problems[] = sprintf(
                'APP_ENV="%s" ไม่ใช่ค่าที่ระบบรู้จัก (%s) — ค่าที่พิมพ์ผิดจะทำให้การป้องกันของ production ไม่ทำงาน',
                $environment,
                implode(', ', self::KNOWN_ENVIRONMENTS),
            );
        }

        if ($isProduction && blank($context['app_key'] ?? null)) {
            $problems[] = 'APP_KEY ต้องถูกตั้งค่าเมื่อ APP_ENV เป็น production';
        }

        // `filesystems.disks.local.serve` เปิด route storage/{path} ที่ไม่ผ่าน
        // authentication ทับทางเข้าไฟล์ที่รับรอง (SEC-001/SEC-021)
        if ($isProduction && ($context['serve_local_disk'] ?? false)) {
            $problems[] = 'filesystems.disks.local.serve ต้องเป็น false เมื่อ APP_ENV เป็น production';
        }

        $appUrl = (string) ($context['app_url'] ?? '');
        if ($isProduction && $appUrl !== '' && str_starts_with(strtolower($appUrl), 'http://')) {
            $problems[] = 'APP_URL ต้องเป็น https:// เมื่อ APP_ENV เป็น production';
        }

        return $problems;
    }

    /**
     * โยนข้อผิดพลาดเมื่อการตั้งค่าไม่ปลอดภัย
     *
     * @param  array{app_key?: string|null, serve_local_disk?: bool, app_url?: string|null}  $context
     *
     * @throws LogicException
     */
    public static function validate(string $environment, bool $debug, array $context = []): void
    {
        $problems = self::problems($environment, $debug, $context);

        if ($problems !== []) {
            throw new LogicException(implode('; ', $problems));
        }
    }

    /**
     * ตรวจจากค่า config ที่ระบบใช้จริง — จุดเรียกเดียวของทั้ง runtime และ CLI
     *
     * @return array<int,string>
     */
    public static function problemsFromConfig(): array
    {
        return self::problems(
            (string) config('app.env'),
            (bool) config('app.debug'),
            [
                'app_key' => config('app.key'),
                'serve_local_disk' => (bool) config('filesystems.disks.local.serve', false),
                'app_url' => config('app.url'),
            ],
        );
    }
}
