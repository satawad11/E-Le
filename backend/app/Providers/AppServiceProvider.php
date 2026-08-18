<?php

namespace App\Providers;

use App\Models\Personnel;
use App\Models\PersonnelAssignment;
use App\Models\User;
use App\Models\UserProfile;
use App\Observers\PersonnelAssignmentObserver;
use App\Observers\PersonnelObserver;
use App\Observers\UserObserver;
use App\Observers\UserProfileObserver;
use App\Services\AuditLogger;
use App\Services\PermissionEvaluator;
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
    public function register(): void {}

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

        User::observe(UserObserver::class);
        Personnel::observe(PersonnelObserver::class);
        UserProfile::observe(UserProfileObserver::class);
        PersonnelAssignment::observe(PersonnelAssignmentObserver::class);

        User::created(fn (User $user) => app(AuditLogger::class)->log('user.created', 'user', $user->id, null, $user->attributesToArray()));
        User::updated(fn (User $user) => app(AuditLogger::class)->log('user.updated', 'user', $user->id, collect($user->getOriginal())->except(['password', 'remember_token'])->all(), $user->attributesToArray()));
        User::deleted(fn (User $user) => app(AuditLogger::class)->log('user.deleted', 'user', $user->id, collect($user->getOriginal())->except(['password', 'remember_token'])->all()));
        /*
         * LEGACY-04 (04-2 / 04-5) — ลำดับ: explicit deny > allow > default deny
         *
         * เดิม Super Admin ผ่านทุก ability โดยไม่มีทางปฏิเสธได้เลย
         * ตอนนี้ explicit deny ชนะแม้กระทั่ง Super Admin
         *
         * ตัว evaluator ยังเทียบชื่อ permission ข้ามรุ่นให้ด้วย (ชื่อเก่า <-> canonical)
         * ซึ่งจำเป็น เพราะฐานข้อมูลจริงยังถือชื่อเก่าอยู่ ขณะที่โค้ดเช็คชื่อ canonical
         *
         * คืน null เมื่อไม่ตัดสิน เพื่อให้ Policy/Gate ปกติทำงานต่อ
         */
        Gate::before(function ($user, $ability) {
            if (! config('authorization.use_new_engine')) {
                return $user->hasRole(['Super Admin', 'SuperAdmin']) ? true : null;
            }

            $evaluator = app(PermissionEvaluator::class);

            if ($user instanceof User && $evaluator->isDenied($user, $ability)) {
                return false;
            }

            if ($user->hasRole(['Super Admin', 'SuperAdmin'])) {
                return true;
            }

            // ให้ผ่านเฉพาะเมื่อถือชื่อที่เทียบเท่ากันอยู่ มิฉะนั้นปล่อยให้ Gate เดิมตัดสิน
            return ($user instanceof User && $evaluator->allows($user, $ability)) ? true : null;
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
