<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * หาผู้ใช้/หน่วยงานจากค่าที่พิมพ์ในคำสั่ง artisan (access:scope-*, personnel:match-positions)
 * รับอีเมลหรือ UUID ไม่รับ id ภายใน (PROJECT_RULES ข้อ 4)
 */
trait ResolvesCommandUsers
{
    protected function findUser(string $identifier, string $label): User
    {
        $identifier = trim($identifier);

        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('uuid', $identifier)
            ->first();

        if ($user === null) {
            throw new InvalidArgumentException("ไม่พบ{$label}: {$identifier}");
        }

        return $user;
    }

    protected function findOrgUnitId(?string $code): ?int
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $id = DB::table('org_units')->where('code', trim($code))->value('id');

        if ($id === null) {
            throw new InvalidArgumentException("ไม่พบหน่วยงานรหัส {$code}");
        }

        return (int) $id;
    }
}
