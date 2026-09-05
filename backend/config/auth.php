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
    | การยืนยันตัวตนซ้ำก่อนทำสิ่งที่อ่อนไหว (SEC-GAP-01 · SoT §24)
    |--------------------------------------------------------------------------
    |
    | โทเคนของระบบมีอายุหลายชั่วโมง การมีโทเคนที่ยังไม่หมดอายุจึงไม่ได้แปลว่า
    | "คนที่กดตอนนี้คือเจ้าของบัญชี" — เครื่องที่เปิดค้างไว้ลงนามหนังสือราชการ
    | แทนเจ้าของได้ ซึ่งเป็นความเสียหายที่ย้อนกลับไม่ได้
    |
    | การกระทำที่ SoT §24 ระบุ (ลงนาม, เพิกถอนเลขที่ออกแล้ว, เผยแพร่นโยบาย ฯลฯ)
    | จึงต้องเกิดในช่วงที่ผู้ใช้เพิ่งยืนยันตัวตนแข็ง
    |
    | ปิดได้เพื่อย้อนกลับทันทีถ้ามีปัญหา (แบบเดียวกับ `USE_NEW_PERMISSION_ENGINE`)
    | แต่ `ProductionEnvironmentGuard` ไม่ยอมให้ระบบขึ้น production ถ้าปิดไว้
    |
    */

    'step_up' => [
        'enabled' => (bool) env('REQUIRE_STEP_UP_REAUTH', true),
        'window_minutes' => (int) env('STEP_UP_WINDOW_MINUTES', 10),
    ],

];
