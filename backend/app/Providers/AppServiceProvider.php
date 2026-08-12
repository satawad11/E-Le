<?php

namespace App\Providers;

use App\Models\Leave;
use App\Models\Saraban\Document;
use App\Policies\LeavePolicy;
use App\Policies\Saraban\DocumentPolicy;
use App\Services\SystemSettingService;
use App\Support\ProductionEnvironmentGuard;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
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
        ProductionEnvironmentGuard::validate(
            (string) config('app.env'),
            (bool) config('app.debug'),
        );

        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Leave::class, LeavePolicy::class);

        // Super Admin bypasses every ability check.
        Gate::before(function ($user, $ability) {
            return $user->hasRole(['Super Admin', 'SuperAdmin']) ? true : null;
        });

        try {
            if (Schema::hasTable('settings')) {
                $settings = app(SystemSettingService::class)->runtimeValues();
                if ($settings->get('mail_host')) {
                    config([
                        'mail.default' => 'smtp',
                        'mail.mailers.smtp.host' => $settings->get('mail_host'),
                        'mail.mailers.smtp.port' => (int) ($settings->get('mail_port') ?: 587),
                        'mail.mailers.smtp.username' => $settings->get('mail_username'),
                        'mail.mailers.smtp.password' => $settings->get('mail_password'),
                        'mail.mailers.smtp.scheme' => match ($settings->get('mail_encryption')) {
                            'ssl' => 'smtps',
                            'none' => null,
                            default => 'smtp',
                        },
                        'mail.from.address' => $settings->get('mail_from_address') ?: config('mail.from.address'),
                        'mail.from.name' => $settings->get('mail_from_name') ?: config('mail.from.name'),
                    ]);
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
