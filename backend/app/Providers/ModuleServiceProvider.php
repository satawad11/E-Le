<?php

namespace App\Providers;

use App\Services\Modules\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, fn () => new ModuleRegistry(config('modules', [])));

        $registry = $this->app->make(ModuleRegistry::class);
        // Providers are container adapters, not feature switches. Registering all
        // keeps queues and in-flight work resolvable while routes/menus are disabled.
        foreach ($registry->all() as $module) {
            foreach ($module->providers() as $provider) {
                $this->app->register($provider);
            }
        }
    }

    public function boot(ModuleRegistry $registry): void
    {
        foreach ($registry->enabled() as $module) {
            foreach ($module->routeFiles() as $routeFile) {
                $path = base_path($routeFile);
                if (! is_file($path)) {
                    throw new \RuntimeException("Route file [{$routeFile}] for module [{$module->code()}] does not exist.");
                }
                $this->loadRoutesFrom($path);
            }
        }
    }
}
