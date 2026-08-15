<?php

namespace App\Providers\Modules;

use App\Models\Saraban\Document;
use App\Policies\Saraban\DocumentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class SarabanModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Document::class, DocumentPolicy::class);
    }
}
