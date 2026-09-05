<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\NotificationCenter\NotificationDelivery;
use App\Models\SystemException;
use App\Models\WebhookDelivery;
use App\Services\NotificationCenter\ChannelAdapterRegistry;
use App\Services\Operations\FeatureFlags;
use App\Services\Operations\SchedulerHeartbeat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * ศูนย์เฝ้าระวังการปฏิบัติการ (SoT §20)
 *
 * รวมสิ่งที่ต้อง "เห็นในหน้าจอเดียว" ตามที่ SoT §20 กำหนด: คิวงาน, งานที่ล้มเหลว,
 * dead-letter, ข้อผิดพลาดที่ค้าง, สุขภาพช่องทางส่ง, พื้นที่จัดเก็บ, การสำรองข้อมูล
 * และการเชื่อมต่อภายนอก
 *
 * **อ่านอย่างเดียว รวมของที่มีอยู่แล้ว ไม่สร้างตัวชี้วัดใหม่** — ตัวเลขทุกตัวมาจาก
 * ตารางที่ระบบใช้ทำงานจริง ไม่ใช่ตารางสรุปที่อาจไม่ตรงกับความจริง
 *
 * **จุดที่สำคัญที่สุดคือชีพจรของงานตามเวลา** (ปิด RISK-40) — งานที่ไม่ทำงานจะเงียบ
 * สนิท ไม่มี error ให้จับ · การเฝ้าระวัง "การไม่เกิดขึ้นของเหตุการณ์" ต้องทำ
 * ด้วยการเทียบเวลา ไม่ใช่รอให้มีอะไรพัง
 */
class CommandCenterController extends Controller
{
    public function __invoke(
        SchedulerHeartbeat $heartbeat,
        ChannelAdapterRegistry $adapters,
        FeatureFlags $flags,
    ) {
        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'exceptions' => $this->exceptionSummary(),
            'queues' => $this->queueSummary(),
            'notifications' => $this->notificationSummary($adapters),
            'webhooks' => $this->webhookSummary(),
            'scheduler' => [
                'tasks' => $heartbeat->overview(),
                'stale' => $heartbeat->staleTasks(),
            ],
            'storage' => $this->storageSummary(),
            'feature_flags' => $flags->overview(),
        ]);
    }

    /** @return array<string,mixed> */
    private function exceptionSummary(): array
    {
        $rows = SystemException::query()
            ->select('status', 'severity', DB::raw('count(*) as total'))
            ->groupBy('status', 'severity')
            ->get();

        return [
            'open' => (int) $rows->where('status', '!=', SystemException::STATUS_RESOLVED)->sum('total'),
            'critical_open' => (int) $rows
                ->where('status', '!=', SystemException::STATUS_RESOLVED)
                ->where('severity', SystemException::SEVERITY_CRITICAL)
                ->sum('total'),
            'unassigned' => SystemException::query()
                ->where('status', '!=', SystemException::STATUS_RESOLVED)
                ->whereNull('assigned_to')
                ->count(),
            'resolved_today' => SystemException::query()
                ->where('status', SystemException::STATUS_RESOLVED)
                ->whereDate('resolved_at', today())
                ->count(),
        ];
    }

    /**
     * งานในคิวของ Laravel
     *
     * อ่านจากตาราง `jobs`/`failed_jobs` โดยตรงถ้ามี — ระบบอาจตั้งคิวเป็น
     * `sync` หรือ Redis ซึ่งไม่มีตารางเหล่านี้ · การถามหาแล้วไม่เจอไม่ใช่
     * ข้อผิดพลาด แต่เป็นข้อเท็จจริงที่หน้าจอต้องบอกได้
     *
     * @return array<string,mixed>
     */
    private function queueSummary(): array
    {
        $builder = DB::getSchemaBuilder();

        return [
            'driver' => config('queue.default'),
            'pending' => $builder->hasTable('jobs') ? DB::table('jobs')->count() : null,
            'failed' => $builder->hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
        ];
    }

    /** @return array<string,mixed> */
    private function notificationSummary(ChannelAdapterRegistry $adapters): array
    {
        $since = now()->subDay();

        $counts = DB::table('notification_deliveries')
            ->where('updated_at', '>=', $since)
            ->select('channel', 'status', DB::raw('count(*) as total'))
            ->groupBy('channel', 'status')
            ->get();

        return [
            'dead_letters' => NotificationDelivery::query()->whereNotNull('dead_lettered_at')->count(),
            'channels' => collect(array_keys($adapters->all()))
                ->map(fn (string $channel) => [
                    'channel' => $channel,
                    'sent' => (int) $counts->where('channel', $channel)->where('status', 'sent')->sum('total'),
                    'failed' => (int) $counts->where('channel', $channel)->where('status', 'failed')->sum('total'),
                ])
                ->values(),
        ];
    }

    /** @return array<string,mixed> */
    private function webhookSummary(): array
    {
        return [
            'dead_letters' => WebhookDelivery::query()->whereNotNull('dead_lettered_at')->count(),
            'pending' => WebhookDelivery::query()->whereIn('status', ['pending', 'retrying'])->count(),
            'sent_today' => WebhookDelivery::query()->whereDate('sent_at', today())->count(),
        ];
    }

    /**
     * พื้นที่จัดเก็บและการสำรองข้อมูล
     *
     * **ห่อด้วย try/catch โดยตั้งใจ** — หน้าจอเฝ้าระวังต้องไม่ล้มทั้งหน้าเพราะ
     * ส่วนใดส่วนหนึ่งอ่านไม่ได้ · ระบบที่มีปัญหาคือระบบที่ต้องเปิดหน้านี้ดูมากที่สุด
     *
     * @return array<string,mixed>
     */
    private function storageSummary(): array
    {
        $result = ['private_uploads' => null, 'latest_backup' => null];

        try {
            $disk = Storage::disk('private_uploads');
            $result['private_uploads'] = [
                'files' => count($disk->allFiles()),
            ];
        } catch (Throwable $exception) {
            $result['private_uploads'] = ['error' => $exception->getMessage()];
        }

        try {
            $backups = collect(Storage::disk(config('backup.backup.destination.disks')[0] ?? 'local')->allFiles())
                ->filter(fn (string $path) => str_ends_with($path, '.zip'))
                ->sort()
                ->values();

            $result['latest_backup'] = $backups->last();
            $result['backup_count'] = $backups->count();
        } catch (Throwable $exception) {
            $result['latest_backup'] = null;
            $result['backup_error'] = $exception->getMessage();
        }

        return $result;
    }
}
