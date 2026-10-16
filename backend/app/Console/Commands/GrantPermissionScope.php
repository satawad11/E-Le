<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCommandUsers;
use App\Services\PermissionScopeService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * ให้ขอบเขตของสิทธิ์รายคน พร้อม audit (ORG-ACCESS-01)
 *
 * ตัวอย่าง: รองผู้อำนวยการเห็นใบลาทั้งองค์กร
 *   php artisan access:scope-grant deputy@college.ac.th leave.approval.dept ORGANIZATION \
 *       --by=admin@college.ac.th --reason="คำสั่งวิทยาลัยที่ 123/2569"
 */
class GrantPermissionScope extends Command
{
    use ResolvesCommandUsers;

    protected $signature = 'access:scope-grant
        {user : อีเมลหรือ UUID ของผู้ได้รับขอบเขต}
        {permission : ชื่อสิทธิ์ เช่น leave.approval.dept}
        {scope : SELF, UNIT, DESCENDANTS, ORGANIZATION, ASSIGNED หรือ CUSTOM}
        {--unit= : รหัสหน่วยงาน (เฉพาะ CUSTOM)}
        {--by= : อีเมลหรือ UUID ของผู้ดูแลที่สั่ง (ต้องมีสิทธิ์จัดการสิทธิ์ผู้ใช้)}
        {--reason= : เหตุผล เช่น เลขที่คำสั่งแต่งตั้ง}';

    protected $description = 'ให้ขอบเขตของสิทธิ์รายคน (permission_scopes) และบันทึก audit';

    public function handle(PermissionScopeService $scopes): int
    {
        try {
            $actor = $this->findUser((string) $this->option('by'), 'ผู้สั่ง');
            $user = $this->findUser((string) $this->argument('user'), 'ผู้ใช้');

            $result = $scopes->grant(
                $actor,
                $user,
                (string) $this->argument('permission'),
                (string) $this->argument('scope'),
                $this->findOrgUnitId($this->option('unit')),
                (string) $this->option('reason'),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("ให้ขอบเขต {$result['row']->scope} ของสิทธิ์ {$result['row']->permission_name} แก่ {$user->email} แล้ว");

        if ($result['warning'] !== null) {
            $this->warn($result['warning']);
        }

        return self::SUCCESS;
    }
}
