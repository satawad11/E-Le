<?php

namespace App\Providers;

use App\Models\Saraban\Document;
use App\Policies\Saraban\DocumentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Document::class, DocumentPolicy::class);

        // Super Admin ผ่านทุก gate/permission (ตรงกับที่ frontend ใช้ isAdmin เป็น bypass อยู่แล้ว)
        Gate::before(function ($user, $ability) {
            return $user->hasRole(DocumentPolicy::ADMIN_ROLES) ? true : null;
        });
    }
}
