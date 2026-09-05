<?php

namespace App\Providers\Modules;

use App\Models\Leave;
use App\Policies\LeavePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class LeaveModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ลงทะเบียนก่อน Core boot เพื่อให้กฎร่างส่วนตัวมีผลก่อนสิทธิ์ผู้ดูแล
        // กฎของโมดูลอยู่ใน provider ของโมดูล ไม่เพิ่ม dependency จาก Core มาที่ Leave
        $this->app->afterResolving(\Illuminate\Contracts\Auth\Access\Gate::class, function ($gate) {
            $gate->before(function ($user, $ability, $arguments = []) {
                $resource = $arguments[0] ?? null;
                if (in_array($ability, ['view', 'download'], true)
                    && $resource instanceof Leave
                    && $resource->status === 'Draft'
                    && (int) $resource->user_id !== (int) $user->id) {
                    return false;
                }

                return null;
            });
        });
    }

    public function boot(): void
    {
        Gate::policy(Leave::class, LeavePolicy::class);
    }
}
