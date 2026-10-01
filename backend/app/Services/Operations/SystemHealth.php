<?php

namespace App\Services\Operations;

use App\Models\NotificationCenter\NotificationDelivery;
use App\Models\SystemException;
use App\Models\WebhookDelivery;
use App\Services\NotificationCenter\ChannelAdapterRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * สุขภาพของระบบ (SoT §27)
 *
 * ตรวจสิ่งที่ SoT §27 กำหนด: DB, cache/Redis, คิว, พื้นที่จัดเก็บ,
 * ตัวเรนเดอร์ PDF, ผู้ให้บริการส่งข้อความ และงานตามเวลา
 *
 * **แต่ละชิ้นถูกห่อด้วย try/catch แยกกัน** — ตัวตรวจสุขภาพที่ล้มทั้งตัวเพราะ
 * ชิ้นเดียวพัง คือตัวตรวจที่ใช้ไม่ได้ในวันที่ต้องใช้มากที่สุด
 *
 * **แยก "สถานะรวม" ออกจาก "รายละเอียด"** เพราะผู้อ่านสองกลุ่มต่างกันโดยสิ้นเชิง:
 * uptime monitor ต้องการแค่ ok/degraded (และต้องเข้าถึงได้โดยไม่ต้องยืนยันตัวตน)
 * ส่วนผู้ดูแลต้องการรายละเอียด ซึ่ง**เป็นข้อมูลภายในที่ต้องมีสิทธิ์**
 */
class SystemHealth
{
    public const OK = 'ok';

    public const DEGRADED = 'degraded';

    public const DOWN = 'down';

    public function __construct(
        private readonly SchedulerHeartbeat $heartbeat,
        private readonly ChannelAdapterRegistry $adapters,
    ) {}

    /**
     * สถานะรวมสำหรับ uptime monitor — **ไม่เปิดเผยรายละเอียดภายใน**
     *
     * ปิด RISK-54 ในส่วนที่โค้ดทำได้: ให้ระบบภายนอกมีจุดเดียวที่ถามได้ว่า
     * "ระบบยังทำงานปกติไหม" โดยไม่ต้องมีบัญชีผู้ใช้
     *
     * @return array{status:string}
     */
    public function summary(): array
    {
        $checks = $this->checks();

        $status = self::OK;

        foreach ($checks as $check) {
            if ($check['status'] === self::DOWN) {
                return ['status' => self::DOWN];
            }

            if ($check['status'] === self::DEGRADED) {
                $status = self::DEGRADED;
            }
        }

        return ['status' => $status];
    }

    /**
     * รายละเอียดทุกชิ้น — สำหรับผู้ดูแลเท่านั้น
     *
     * @return array<int,array<string,mixed>>
     */
    public function checks(): array
    {
        return [
            $this->database(),
            $this->cache(),
            $this->queue(),
            $this->storage(),
            $this->pdfRenderer(),
            $this->notificationProviders(),
            $this->scheduler(),
            $this->exceptions(),
        ];
    }

    private function database(): array
    {
        return $this->guard('database', function () {
            $start = microtime(true);
            DB::connection()->select('select 1');
            $ms = (int) round((microtime(true) - $start) * 1000);

            return [
                // ฐานข้อมูลที่ตอบช้ากว่า 1 วินาที = ระบบยังไม่ตายแต่กำลังจะมีปัญหา
                'status' => $ms < 1000 ? self::OK : self::DEGRADED,
                'detail' => ['driver' => DB::connection()->getDriverName(), 'latency_ms' => $ms],
            ];
        });
    }

    private function cache(): array
    {
        return $this->guard('cache', function () {
            $key = 'health-check-probe';
            Cache::put($key, 'ok', 10);
            $value = Cache::get($key);
            Cache::forget($key);

            return [
                'status' => $value === 'ok' ? self::OK : self::DOWN,
                'detail' => ['store' => config('cache.default')],
            ];
        });
    }

    private function queue(): array
    {
        return $this->guard('queue', function () {
            $builder = DB::getSchemaBuilder();
            $failed = $builder->hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
            $pending = $builder->hasTable('jobs') ? DB::table('jobs')->count() : 0;

            /*
             * งานค้างจำนวนมากไม่ใช่ความล้มเหลว แต่เป็นสัญญาณว่า worker ตามไม่ทัน
             * หรือหยุดไป · ตัวเลข 500 เป็นเกณฑ์หยาบที่ปรับได้เมื่อรู้ปริมาณจริง
             */
            return [
                'status' => $failed > 0 || $pending > 500 ? self::DEGRADED : self::OK,
                'detail' => ['driver' => config('queue.default'), 'pending' => $pending, 'failed' => $failed],
            ];
        });
    }

    private function storage(): array
    {
        return $this->guard('storage', function () {
            $disk = Storage::disk('private_uploads');
            $probe = 'health/probe-'.uniqid().'.txt';

            $disk->put($probe, 'ok');
            $readable = $disk->get($probe) === 'ok';
            $disk->delete($probe);

            return [
                // เขียนไม่ได้ = อัปโหลดหนังสือไม่ได้ = ระบบใช้งานจริงไม่ได้
                'status' => $readable ? self::OK : self::DOWN,
                'detail' => ['disk' => 'private_uploads'],
            ];
        });
    }

    /**
     * ตัวเรนเดอร์ PDF ต้องมีฟอนต์ไทยจึงจะทำงานได้
     *
     * ระบบที่เรนเดอร์เอกสารไม่ได้คือระบบที่ออกหนังสือไม่ได้ — แต่ยังอ่านทะเบียน
     * ได้อยู่ จึงเป็น `degraded` ไม่ใช่ `down`
     */
    private function pdfRenderer(): array
    {
        return $this->guard('pdf_renderer', function () {
            /*
             * ตรวจ **ไฟล์ฟอนต์ที่ตัวเรนเดอร์ใช้จริง** ไม่ใช่แค่ว่ามีโฟลเดอร์
             *
             * ตำแหน่งและนามสกุลตรงกับ `OverlayFonts` ของ Document Engine —
             * ถ้าตรวจคนละที่กับที่ใช้จริง ตัวตรวจจะรายงานว่าพร้อมทั้งที่เรนเดอร์
             * ไม่ได้ หรือรายงานว่าไม่พร้อมทั้งที่ใช้งานได้ (ซึ่งเกิดขึ้นจริง
             * ตอนพัฒนา INT-22 — ตัวตรวจมองหา `storage/fonts/*.php`
             * แต่ฟอนต์อยู่ที่ `storage/fonts/fpdf/*.json`)
             */
            $fontPath = storage_path('fonts/fpdf');
            $installed = is_file($fontPath.'/THSarabunNew.json')
                || is_file($fontPath.'/THSarabunNew.php');

            return [
                'status' => $installed ? self::OK : self::DEGRADED,
                'detail' => [
                    'thai_fonts_installed' => $installed,
                    // INT-18 — ไม่มี ghostscript = ปกปิดข้อมูลอย่างปลอดภัยไม่ได้
                    'ghostscript_available' => is_executable('/usr/bin/gs') || is_executable('/usr/local/bin/gs'),
                ],
            ];
        });
    }

    private function notificationProviders(): array
    {
        return $this->guard('notification_providers', function () {
            $dead = NotificationDelivery::query()->whereNotNull('dead_lettered_at')->count()
                + WebhookDelivery::query()->whereNotNull('dead_lettered_at')->count();

            return [
                'status' => $dead === 0 ? self::OK : self::DEGRADED,
                'detail' => [
                    'channels' => array_keys($this->adapters->all()),
                    'dead_lettered' => $dead,
                ],
            ];
        });
    }

    private function scheduler(): array
    {
        return $this->guard('scheduler', function () {
            $stale = $this->heartbeat->staleTasks();
            $known = count($this->heartbeat->overview());

            return [
                /*
                 * ยังไม่เคยมีชีพจรเลย = ไม่รู้ ไม่ใช่ปกติ — ระบบที่เพิ่งติดตั้ง
                 * กับระบบที่ scheduler ไม่เคยทำงานเลย มีหน้าตาเหมือนกันทุกอย่าง
                 */
                'status' => $known === 0 ? self::DEGRADED : ($stale === [] ? self::OK : self::DOWN),
                'detail' => ['known_tasks' => $known, 'stale_tasks' => $stale],
            ];
        });
    }

    private function exceptions(): array
    {
        return $this->guard('exceptions', function () {
            $critical = SystemException::query()
                ->where('status', '!=', SystemException::STATUS_RESOLVED)
                ->where('severity', SystemException::SEVERITY_CRITICAL)
                ->count();

            return [
                'status' => $critical === 0 ? self::OK : self::DEGRADED,
                'detail' => ['open_critical' => $critical],
            ];
        });
    }

    /**
     * @param  callable():array{status:string,detail:array<string,mixed>}  $probe
     * @return array<string,mixed>
     */
    private function guard(string $name, callable $probe): array
    {
        try {
            return ['name' => $name, ...$probe()];
        } catch (Throwable $exception) {
            /*
             * ตรวจไม่ได้ ≠ ปกติ — ต้องเป็น `down` เสมอ · ตัวตรวจที่กลืนข้อผิดพลาด
             * แล้วรายงานว่าปกติ อันตรายกว่าการไม่มีตัวตรวจเลย
             */
            return [
                'name' => $name,
                'status' => self::DOWN,
                'detail' => ['error' => $exception->getMessage()],
            ];
        }
    }
}
