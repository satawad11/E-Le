<?php

namespace App\Providers\Modules;

use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentCase;
use App\Policies\Saraban\DocumentCasePolicy;
use App\Policies\Saraban\DocumentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * ผูก policy ของโมดูลสารบรรณเข้ากับ Gate กลาง (INT-05)
 *
 * ลงทะเบียนผ่าน `providers` ใน `config/module_registry.php` แบบเดียวกับโมดูลอื่น
 * — `ModuleServiceProvider` register provider ของทุกโมดูลเสมอแม้โมดูลถูกปิด
 * เพื่อให้งานที่ค้างอยู่ในคิวยัง resolve ได้ ส่วนการปิดโมดูลจัดการที่ route/menu
 */
class SarabanModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(DocumentCase::class, DocumentCasePolicy::class);
    }
}
