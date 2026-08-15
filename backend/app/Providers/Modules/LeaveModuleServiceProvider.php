<?php

namespace App\Providers\Modules;

use App\Models\Leave;
use App\Policies\LeavePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class LeaveModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Leave::class, LeavePolicy::class);
    }
}
