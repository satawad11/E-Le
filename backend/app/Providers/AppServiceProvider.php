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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use LogicException;

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
        $this->configureRateLimiters();

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            return rtrim((string) config('app.frontend_url'), '/')
                .'/reset-password?token='.urlencode($token)
                .'&email='.urlencode((string) $notifiable->getEmailForPasswordReset());
        });

        /*
         * SEC-022 — ด่านตรวจ environment
         *
         * ทาง HTTP: ปฏิเสธทุกคำขอทันทีเมื่อการตั้งค่าไม่ปลอดภัย
         *
         * ทาง console: ไม่โยน เพราะถ้าโยน ผู้ดูแลจะรัน artisan อะไรไม่ได้เลย
         * รวมถึงคำสั่งที่ใช้ "แก้" ปัญหา (config:clear, key:generate)
         * ด่านของฝั่ง container คือ `php artisan app:verify-environment`
         * ซึ่ง docker-compose.yml รันเป็นคำสั่งแรกของทุก service
         * เมื่อไม่ผ่าน คำสั่งถัดไปจะไม่ถูกรันและ container ออกทันที
         */
        if (! $this->app->runningInConsole()) {
            $problems = ProductionEnvironmentGuard::problemsFromConfig();

            if ($problems !== []) {
                throw new LogicException(implode('; ', $problems));
            }
        }

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

    /**
     * SEC-019 — แยก "ถัง" ของ rate limit ให้แต่ละกลุ่มการใช้งาน
     *
     * `throttle:10,1` แบบ inline ของ Laravel ตั้งกุญแจเป็น sha1(user id) เพียงอย่างเดียว
     * ไม่มีชื่อ route อยู่ในกุญแจ ทุก route ที่ประกาศแบบนี้จึงนับรวมถังเดียวกัน
     * และถังนั้นยังถูกนับเพิ่มโดย `throttle:60,1` ของกลุ่มใหญ่ในทุก request ด้วย
     *
     * ผลคือเปิดหน้าเว็บธรรมดาไม่กี่หน้าก็ใช้โควตาครบ 10 ครั้งภายในนาทีเดียว
     * แล้วปุ่มพิมพ์ใบลา (`/leaves/{leave}/pdf`) ตอบ 429 ทันที ทั้งที่ผู้ใช้ยังไม่เคยสั่งพิมพ์
     *
     * named limiter ผูกชื่อ limiter ไว้ในกุญแจ (md5(ชื่อ.กุญแจ)) แต่ละกลุ่มจึงแยกถังกันจริง
     */
    private function configureRateLimiters(): void
    {
        $perUser = fn (Request $request) => $request->user()?->getAuthIdentifier() ?: $request->ip();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($perUser($request)));
        RateLimiter::for('public-api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('verify', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('password', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('token-refresh', fn (Request $request) => Limit::perMinute(10)->by($perUser($request)));
        RateLimiter::for('personal-data', fn (Request $request) => Limit::perMinute(10)->by($perUser($request)));

        // พิมพ์/ดาวน์โหลดเอกสารรายฉบับ (ใบลา ใบยกเลิก บัตรลา ไฟล์แนบ)
        RateLimiter::for('documents', fn (Request $request) => Limit::perMinute(30)->by($perUser($request)));

        // ส่งออกรายงานทั้งชุด — หนักกว่าเอกสารรายฉบับมาก จึงคุมเข้มกว่า
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(10)->by($perUser($request)));

        RateLimiter::for('files', fn (Request $request) => Limit::perMinute(30)->by($perUser($request)));
    }
}
