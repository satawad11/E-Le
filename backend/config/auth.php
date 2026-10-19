<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    |--------------------------------------------------------------------------
    | เข้าสู่ระบบด้วยเลขประจำตัวประชาชน (ADR-R-013 · Q10)
    |--------------------------------------------------------------------------
    |
    | ปิดเป็นค่าเริ่มต้น — ตัวระบุหลักคืออีเมลหรือรหัสบุคลากร (personnel_code)
    | เลขประจำตัวประชาชนเป็นข้อมูลชั้น sensitive ค้นผ่าน national_id_hash เท่านั้น
    |
    */

    'allow_national_id_login' => (bool) env('AUTH_ALLOW_NATIONAL_ID_LOGIN', false),

    /*
    |--------------------------------------------------------------------------
    | นโยบายบัญชีและความปลอดภัย (CORE-03 · R-05)
    |--------------------------------------------------------------------------
    |
    | - ล็อกบัญชี lockout.minutes นาทีเมื่อรหัสผ่าน/รหัส MFA ผิดครบ lockout.attempts ครั้ง
    | - MFA บังคับ: ผู้มี management/acting ในหน่วยระดับ mfa.levels · ชุดสิทธิ์ที่ manifest ประกาศ `mfa` · break-glass
    | - บัญชีบุคลากรที่ไม่เข้าใช้ inactive_days วัน ถูกระงับโดย accounts:reconcile
    | - อายุ token (เซสชัน) ใช้ sanctum.expiration (8 ชั่วโมง ตาม ADR-005 · ทบทวนแล้วใน R-05)
    |
    */

    'lockout' => [
        'attempts' => (int) env('AUTH_LOCKOUT_ATTEMPTS', 5),
        'minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 30),
    ],

    'mfa' => [
        'issuer' => env('AUTH_MFA_ISSUER', 'DMTC'),
        'levels' => [1, 2, 3],
        'challenge_seconds' => 300,
        'challenge_attempts' => 5,
        'recovery_codes' => 8,
    ],

    /*
     * อุปกรณ์ที่รู้จัก: ติ๊ก "จดจำอุปกรณ์นี้" = รู้จัก remember_days วัน · ไม่ติ๊ก = session_hours ชั่วโมง
     * มีผลเฉพาะการแจ้งเตือนอุปกรณ์ใหม่ — ไม่ข้าม MFA (ADR-R-020)
     */
    'devices' => [
        'remember_days' => 30,
        'session_hours' => 12,
    ],

    'inactive_days' => (int) env('AUTH_INACTIVE_DAYS', 90),

    /*
     * ผู้รับแจ้งเตือนเรื่องบัญชี — ระบุด้วยชุดสิทธิ์ (template) ไม่ใช่ชื่อ role
     * งานบุคคล: บัญชีถูกระงับเพราะไม่เข้าใช้ และงานค้างของผู้พ้นสภาพ · ผู้ตรวจสอบ: ทุกการเข้าใช้บัญชี break-glass
     */
    'notify' => [
        'hr_templates' => ['personnel.hr_officer'],
        'auditor_templates' => ['audit.auditor'],
    ],

];
