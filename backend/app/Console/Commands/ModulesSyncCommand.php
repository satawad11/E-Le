<?php

namespace App\Console\Commands;

use App\Authz\Manifest\ManifestException;
use App\Authz\Manifest\ModuleSynchronizer;
use Illuminate\Console\Command;

/**
 * R04-1 — sync manifest ของทุกโมดูลเข้าตารางสิทธิ์ (idempotent · retire ไม่ลบ)
 */
class ModulesSyncCommand extends Command
{
    protected $signature = 'modules:sync {--reset-defaults : คืนค่า template_permissions และ defaults ตาม manifest (ทับการปรับของผู้ดูแล)}';

    protected $description = 'สร้าง/อัปเดตโมดูล permission ชุดสิทธิ์ และค่าเริ่มต้นตามตำแหน่งจาก manifest';

    public function handle(ModuleSynchronizer $synchronizer): int
    {
        if ($this->option('reset-defaults') && $this->input->isInteractive()
            && ! $this->confirm('คืนค่าชุดสิทธิ์ทั้งหมดตาม manifest? การปรับของผู้ดูแลจะหายไป')) {
            return self::FAILURE;
        }

        try {
            $stats = $synchronizer->sync((bool) $this->option('reset-defaults'));
        } catch (ManifestException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['รายการ', 'จำนวน'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->info('sync manifest เรียบร้อย');

        return self::SUCCESS;
    }
}
