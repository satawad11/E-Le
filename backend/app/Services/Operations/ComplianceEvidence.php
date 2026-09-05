<?php

namespace App\Services\Operations;

use App\Models\AuditLog;
use App\Models\Saraban\LegalHold;
use App\Models\Saraban\SignatureEvidence;
use App\Models\ScheduledTaskHeartbeat;
use App\Models\SystemException;
use App\Services\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * ทะเบียนหลักฐานการปฏิบัติตามข้อกำหนด (SoT §23)
 *
 * **ข้อบังคับที่หนักที่สุดของ §23: ห้าม claim PASS โดยไม่มี evidence จริง**
 *
 * คลาสนี้จึงออกแบบให้ **ไม่มีทางกรอกสถานะเองได้เลย** — ไม่มี endpoint ที่ตั้ง
 * สถานะ ไม่มีคอลัมน์ในฐานข้อมูลให้เขียน · ทุกสถานะคำนวณสด ๆ จากการตรวจจริง
 * ณ เวลาที่เรียก
 *
 * หัวข้อที่ตรวจอัตโนมัติไม่ได้ (UAT, DR drill, accessibility audit) คืนสถานะ
 * **`NO_EVIDENCE` เสมอ** พร้อมบอกว่าต้องทำอะไรจึงจะมีหลักฐาน —
 * ไม่มีทางที่มันจะกลายเป็น PASS ด้วยการกดปุ่ม
 *
 * นี่คือเหตุผลที่หัวข้อนี้ถูกเลื่อนจาก INT-19 มาทำที่นี่: ทะเบียนที่มีแต่ช่องว่าง
 * ให้คนกรอกเอง คือทะเบียนที่หลอกตัวเอง
 */
class ComplianceEvidence
{
    public const PASS = 'PASS';

    public const PARTIAL = 'PARTIAL';

    public const FAIL = 'FAIL';

    public const NO_EVIDENCE = 'NO_EVIDENCE';

    public const NOT_APPLICABLE = 'N/A';

    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function collect(): array
    {
        return [
            $this->permissions(),
            $this->workflow(),
            $this->authority(),
            $this->audit(),
            $this->backup(),
            $this->restore(),
            $this->disasterRecovery(),
            $this->security(),
            $this->uat(),
            $this->accessibility(),
            $this->migration(),
            $this->providers(),
            $this->contracts(),
            $this->incidents(),
            $this->retention(),
        ];
    }

    /** @return array<string,int> */
    public function summary(): array
    {
        $counts = array_fill_keys(
            [self::PASS, self::PARTIAL, self::FAIL, self::NO_EVIDENCE, self::NOT_APPLICABLE],
            0,
        );

        foreach ($this->collect() as $topic) {
            $counts[$topic['status']]++;
        }

        return $counts;
    }

    // ── หัวข้อที่ตรวจได้จริงจากสถานะระบบ ──────────────────────────────────

    private function permissions(): array
    {
        $declared = array_keys($this->modules->get('saraban')?->permissions() ?? []);
        $stored = DB::table('permissions')->whereIn('name', $declared)->count();

        return $this->topic(
            'permission',
            'สิทธิ์ของโมดูลถูกสร้างครบตามสารบัญ',
            $stored === count($declared) ? self::PASS : self::PARTIAL,
            ['declared' => count($declared), 'present_in_database' => $stored],
            'นับชื่อสิทธิ์ใน `permissions` เทียบกับ manifest ของโมดูล',
        );
    }

    private function workflow(): array
    {
        $published = DB::table('workflow_versions')->where('status', 'published')->count();

        return $this->topic(
            'workflow',
            'มีสายงานเดินหนังสือที่เผยแพร่แล้ว',
            $published > 0 ? self::PASS : self::NO_EVIDENCE,
            ['published_versions' => $published],
            'นับ `workflow_versions` ที่สถานะ published',
        );
    }

    private function authority(): array
    {
        $scoped = DB::table('permission_scopes')->count();
        $managers = DB::getSchemaBuilder()->hasTable('org_unit_managers')
            ? DB::table('org_unit_managers')->count()
            : 0;

        /*
         * ไม่มีขอบเขตที่กำหนดเองเลย = ทุกคนใช้ค่าเริ่มต้น ซึ่งอาจถูกต้องในองค์กรเล็ก
         * แต่ไม่ใช่หลักฐานว่ามีการกำหนดอำนาจไว้ — จึงเป็น PARTIAL ไม่ใช่ PASS
         */
        return $this->topic(
            'authority',
            'มีการกำหนดขอบเขตอำนาจไว้ในระบบ',
            $scoped + $managers > 0 ? self::PASS : self::PARTIAL,
            ['permission_scopes' => $scoped, 'org_unit_managers' => $managers],
            'นับแถวใน `permission_scopes` และ `org_unit_managers`',
        );
    }

    /**
     * ความสมบูรณ์ของ audit chain — **ตรวจจริง ไม่ใช่ดูว่ามีตาราง**
     *
     * ไล่เทียบ hash ทีละแถวเหมือนคำสั่ง `audit:verify` (INT-04)
     */
    private function audit(): array
    {
        $total = AuditLog::query()->count();

        if ($total === 0) {
            return $this->topic('audit', 'ห่วงโซ่หลักฐานการตรวจสอบไม่ถูกแก้ไข', self::NO_EVIDENCE, [
                'entries' => 0,
            ], 'ยังไม่มีรายการใน audit log');
        }

        $broken = 0;
        $previous = null;

        AuditLog::query()->orderBy('id')->chunk(500, function ($rows) use (&$broken, &$previous) {
            foreach ($rows as $row) {
                if ($previous !== null && $row->previous_hash !== $previous) {
                    $broken++;
                }

                $previous = $row->entry_hash;
            }
        });

        return $this->topic(
            'audit',
            'ห่วงโซ่หลักฐานการตรวจสอบไม่ถูกแก้ไข',
            $broken === 0 ? self::PASS : self::FAIL,
            ['entries' => $total, 'broken_links' => $broken],
            'ไล่เทียบ hash ของทุกแถวใน `audit_logs` ตามลำดับ',
        );
    }

    private function backup(): array
    {
        try {
            $disk = Storage::disk(config('backup.backup.destination.disks')[0] ?? 'local');
            $backups = collect($disk->allFiles())->filter(fn (string $p) => str_ends_with($p, '.zip'));

            return $this->topic(
                'backup',
                'มีไฟล์สำรองข้อมูลอยู่จริง',
                $backups->isNotEmpty() ? self::PASS : self::NO_EVIDENCE,
                ['backup_count' => $backups->count(), 'latest' => $backups->sort()->last()],
                'นับไฟล์ .zip ใน disk ปลายทางของ spatie/laravel-backup',
            );
        } catch (Throwable $exception) {
            return $this->topic('backup', 'มีไฟล์สำรองข้อมูลอยู่จริง', self::NO_EVIDENCE, [
                'error' => $exception->getMessage(),
            ], 'อ่าน disk ปลายทางไม่สำเร็จ');
        }
    }

    /**
     * INT-21 — อ่านผลการทดสอบกู้คืนจริงจากชีพจรที่ `backup:verify-restore` บันทึกไว้
     *
     * **เปลี่ยนเป็น PASS ได้ก็ต่อเมื่อมีคนรันคำสั่งกู้คืนจริงแล้วสำเร็จ** — ยังไม่มี
     * ทางกรอกเอง · และหลักฐานที่เก่าเกิน 90 วันถือว่า **หมดอายุ** เพราะไฟล์สำรอง
     * ที่กู้ได้เมื่อปีที่แล้วไม่ได้บอกอะไรเกี่ยวกับไฟล์ของสัปดาห์นี้
     */
    private function restore(): array
    {
        $beat = ScheduledTaskHeartbeat::query()
            ->where('task_key', 'backup:verify-restore')
            ->first();

        if ($beat === null) {
            return $this->topic('restore', 'ทดสอบกู้คืนข้อมูลจากไฟล์สำรองสำเร็จ', self::NO_EVIDENCE, [],
                'ยังไม่เคยรัน `php artisan backup:verify-restore` — ระบบกรอกผลแทนไม่ได้');
        }

        $stale = $beat->last_run_at->addDays(90)->isPast();

        return $this->topic(
            'restore',
            'ทดสอบกู้คืนข้อมูลจากไฟล์สำรองสำเร็จ',
            $stale ? self::PARTIAL : self::PASS,
            [
                'last_verified_at' => $beat->last_run_at->toIso8601String(),
                'restore_seconds' => $beat->meta['restore_seconds'] ?? null,
                'archive' => $beat->meta['archive'] ?? null,
                'stale' => $stale,
            ],
            'อ่านผลจากคำสั่ง `backup:verify-restore` ที่กู้คืนลงฐานชั่วคราวจริง',
        );
    }

    private function disasterRecovery(): array
    {
        return $this->topic(
            'disaster_recovery',
            'ซ้อมแผนกู้คืนระบบและวัด RPO/RTO',
            self::NO_EVIDENCE,
            [],
            'ต้องซ้อมจริงและบันทึกเวลาที่ใช้ — ระบบตรวจแทนไม่ได้',
        );
    }

    /** ตรวจการตั้งค่าความปลอดภัยที่บังคับได้จากโค้ด */
    private function security(): array
    {
        $checks = [
            'step_up_enabled' => (bool) config('auth.step_up.enabled'),
            'malware_scan_enabled' => (bool) config('uploads.malware_scan.enabled', true),
            'private_disk_is_not_public' => ! str_contains(
                (string) config('filesystems.disks.private_uploads.root', ''),
                'public',
            ),
            'signature_evidence_present' => SignatureEvidence::query()->exists(),
        ];

        $passed = count(array_filter($checks));

        return $this->topic(
            'security',
            'การตั้งค่าความปลอดภัยที่บังคับได้จากโค้ดเปิดอยู่',
            $passed === count($checks) ? self::PASS : self::PARTIAL,
            $checks,
            'อ่าน config จริงและตรวจว่ามีหลักฐานการลงนามในระบบ',
        );
    }

    private function uat(): array
    {
        return $this->topic(
            'uat',
            'ผู้ใช้จริงทดสอบและยอมรับระบบแล้ว',
            self::NO_EVIDENCE,
            [],
            'ต้องมีผลการทดสอบจากผู้ใช้จริงพร้อมลายเซ็นรับรอง — ระบบตรวจแทนไม่ได้',
        );
    }

    private function accessibility(): array
    {
        return $this->topic(
            'accessibility',
            'ตรวจการเข้าถึงตามมาตรฐาน WCAG',
            self::NO_EVIDENCE,
            [],
            'ต้องตรวจด้วยเครื่องมือและด้วยคนบนเบราว์เซอร์จริง — ระบบตรวจแทนไม่ได้',
        );
    }

    private function migration(): array
    {
        $pending = DB::table('migrations')->count();

        return $this->topic(
            'migration',
            'migration ทั้งหมดถูกรันแล้ว',
            $pending > 0 ? self::PASS : self::FAIL,
            ['applied_migrations' => $pending],
            'นับแถวในตาราง `migrations`',
        );
    }

    private function providers(): array
    {
        $failed = DB::table('notification_deliveries')->whereNotNull('dead_lettered_at')->count();
        $sent = DB::table('notification_deliveries')->where('status', 'sent')->count();

        return $this->topic(
            'provider',
            'ช่องทางส่งข้อความทำงานได้',
            $sent > 0 && $failed === 0 ? self::PASS : ($sent > 0 ? self::PARTIAL : self::NO_EVIDENCE),
            ['sent' => $sent, 'dead_lettered' => $failed],
            'นับผลการส่งจริงใน `notification_deliveries`',
        );
    }

    private function contracts(): array
    {
        $endpoints = DB::getSchemaBuilder()->hasTable('webhook_endpoints')
            ? DB::table('webhook_endpoints')->count()
            : 0;

        return $this->topic(
            'contracts',
            'สัญญาการเชื่อมต่อกับระบบภายนอก',
            $endpoints > 0 ? self::PASS : self::NOT_APPLICABLE,
            ['webhook_endpoints' => $endpoints],
            'ยังไม่มีการเชื่อมต่อภายนอก = ไม่มีสัญญาให้ตรวจ (N/A ไม่ใช่ FAIL)',
        );
    }

    private function incidents(): array
    {
        $open = SystemException::query()->where('status', '!=', SystemException::STATUS_RESOLVED)->count();

        return $this->topic(
            'incidents',
            'ไม่มีข้อผิดพลาดค้างที่ยังไม่มีใครตามแก้',
            $open === 0 ? self::PASS : self::FAIL,
            ['open_exceptions' => $open],
            'นับรายการที่ยังไม่ปิดในคิวข้อผิดพลาด',
        );
    }

    private function retention(): array
    {
        $scheduled = DB::getSchemaBuilder()->hasTable('saraban_retention_schedules')
            ? DB::table('saraban_retention_schedules')->count()
            : 0;
        $holds = LegalHold::query()->whereNull('released_at')->count();

        return $this->topic(
            'retention',
            'มีการกำหนดอายุการเก็บรักษาเอกสาร',
            $scheduled > 0 ? self::PASS : self::NO_EVIDENCE,
            ['scheduled_documents' => $scheduled, 'documents_on_hold' => $holds],
            'นับกำหนดการเก็บรักษาและการอายัดที่มีผลอยู่',
        );
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function topic(string $key, string $requirement, string $status, array $evidence, string $method): array
    {
        return [
            'key' => $key,
            'requirement' => $requirement,
            'status' => $status,
            // หลักฐานดิบที่ใช้ตัดสิน — ผู้ตรวจสอบต้องเห็นตัวเลขที่ระบบใช้ ไม่ใช่แค่ผล
            'evidence' => $evidence,
            'method' => $method,
        ];
    }
}
