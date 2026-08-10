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

        // Super Admin bypasses every ability check.
        Gate::before(function ($user, $ability) {
            return $user->hasRole(['Super Admin', 'SuperAdmin']) ? true : null;
        });
    }
}
