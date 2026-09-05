<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * INT-14 — Web Push (SoT §16)
     *
     * กุญแจ VAPID เป็นความลับของระบบ ไม่ใช่ค่าตั้งค่าที่ผู้ดูแลแก้จากหน้าเว็บ
     * จึงอยู่ใน env ไม่ใช่ตาราง `settings` เหมือน token ของ LINE/Telegram
     * — กุญแจส่วนตัวที่หลุดออกไปทำให้ปลอมการแจ้งเตือนถึงผู้ใช้ทุกคนได้
     *
     * สร้างคู่กุญแจด้วย `php artisan notifications:generate-vapid-keys`
     */
    'web_push' => [
        'subject' => env('WEBPUSH_SUBJECT', env('APP_URL', 'http://localhost')),
        'public_key' => env('WEBPUSH_PUBLIC_KEY'),
        'private_key' => env('WEBPUSH_PRIVATE_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
