<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesScopeCommandUsers;
use App\Services\PermissionScopeService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * ถอนขอบเขตของสิทธิ์รายคน พร้อม audit (ORG-ACCESS-01)
 *
 * ใช้เมื่อผู้ได้รับขอบเขตพ้นตำแหน่ง เพราะ `permission_scopes` ไม่มีวันหมดอายุ
 * หลังถอน ขอบเขตกลับไปเป็นตามการเป็นหัวหน้าหน่วยงาน หรือค่าเริ่มต้นของแต่ละหน้าจอ
 */
class RevokePermissionScope extends Command
{
    use ResolvesScopeCommandUsers;

    protected $signature = 'access:scope-revoke
        {user : อีเมลหรือ UUID ของผู้ถูกถอนขอบเขต}
        {permission : ชื่อสิทธิ์ เช่น leave.approval.dept}
        {--by= : อีเมลหรือ UUID ของผู้ดูแลที่สั่ง (ต้องมีสิทธิ์จัดการสิทธิ์ผู้ใช้)}
        {--reason= : เหตุผล เช่น พ้นตำแหน่งตามคำสั่งที่ ...}';

    protected $description = 'ถอนขอบเขตของสิทธิ์รายคน (permission_scopes) และบันทึก audit';

    public function handle(PermissionScopeService $scopes): int
    {
        try {
            $actor = $this->findUser((string) $this->option('by'), 'ผู้สั่ง');
            $user = $this->findUser((string) $this->argument('user'), 'ผู้ใช้');

            $scopes->revoke($actor, $user, (string) $this->argument('permission'), (string) $this->option('reason'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("ถอนขอบเขตของสิทธิ์ {$this->argument('permission')} จาก {$user->email} แล้ว");

        return self::SUCCESS;
    }
}
