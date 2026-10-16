<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCommandUsers;
use App\Services\PermissionScopeService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * แสดงขอบเขตของสิทธิ์รายคนที่กำหนดไว้ (ORG-ACCESS-01) — ใช้ตรวจก่อน/หลังเปลี่ยนตัวผู้ดำรงตำแหน่ง
 */
class ListPermissionScopes extends Command
{
    use ResolvesCommandUsers;

    protected $signature = 'access:scope-list {user? : อีเมลหรือ UUID (เว้นว่าง = ทุกคน)}';

    protected $description = 'แสดงขอบเขตของสิทธิ์รายคน (permission_scopes)';

    public function handle(PermissionScopeService $scopes): int
    {
        try {
            $user = $this->argument('user') === null ? null : $this->findUser((string) $this->argument('user'), 'ผู้ใช้');
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = $scopes->list($user);

        if ($rows->isEmpty()) {
            $this->info('ไม่มีขอบเขตที่กำหนดไว้');

            return self::SUCCESS;
        }

        $this->table(
            ['อีเมล', 'ชื่อ', 'สิทธิ์', 'ขอบเขต', 'หน่วยงาน', 'แก้ไขล่าสุด'],
            $rows->map(fn (object $row) => [$row->email, $row->name, $row->permission_name, $row->scope, $row->org_unit_code, $row->updated_at])->all(),
        );

        return self::SUCCESS;
    }
}
