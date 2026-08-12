<?php

namespace App\Providers;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Saraban\Document;
use App\Models\User;
use App\Policies\LeavePolicy;
use App\Policies\Saraban\DocumentPolicy;
use App\Services\AuditLogger;
use App\Services\SystemSettingService;
use App\Support\ProductionEnvironmentGuard;
use Illuminate\Auth\Notifications\ResetPassword;
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
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            return rtrim((string) config('app.frontend_url'), '/')
                .'/reset-password?token='.urlencode($token)
                .'&email='.urlencode((string) $notifiable->getEmailForPasswordReset());
        });

        ProductionEnvironmentGuard::validate(
            (string) config('app.env'),
            (bool) config('app.debug'),
        );

        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Leave::class, LeavePolicy::class);

        User::created(fn (User $user) => app(AuditLogger::class)->log('user.created', 'user', $user->id, null, $user->getAttributes()));
        User::updated(fn (User $user) => app(AuditLogger::class)->log('user.updated', 'user', $user->id, $user->getOriginal(), $user->getAttributes()));
        User::deleted(fn (User $user) => app(AuditLogger::class)->log('user.deleted', 'user', $user->id, $user->getOriginal()));
        Attendance::created(fn (Attendance $attendance) => app(AuditLogger::class)->log('attendance.created', 'attendance', $attendance->id, null, $attendance->getAttributes()));
        Attendance::updated(fn (Attendance $attendance) => app(AuditLogger::class)->log('attendance.updated', 'attendance', $attendance->id, $attendance->getOriginal(), $attendance->getAttributes()));
        Attendance::deleted(fn (Attendance $attendance) => app(AuditLogger::class)->log('attendance.deleted', 'attendance', $attendance->id, $attendance->getOriginal()));

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
