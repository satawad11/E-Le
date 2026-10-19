<?php

namespace Database\Seeders;

use App\Authz\Manifest\ModuleSynchronizer;
use Illuminate\Database\Seeder;

/**
 * R-04 — sync manifest ของทุกโมดูลเข้าตารางสิทธิ์ (เหมือน `php artisan modules:sync`)
 * ติดตั้งใหม่จึงได้ permission ชุดสิทธิ์ และค่าเริ่มต้นตามระดับครบทันที
 */
class ModuleManifestSeeder extends Seeder
{
    public function run(ModuleSynchronizer $synchronizer): void
    {
        $synchronizer->sync();
    }
}
