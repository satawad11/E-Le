<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        /*
         * เดิมไฟล์อัปโหลดทั้งหมด (ลายเซ็น รูปโปรไฟล์ ภาพลงเวลา ไฟล์แนบสารบรรณ)
         * อยู่บน disk 'public' ซึ่งเข้าถึงได้จากอินเทอร์เน็ตโดยไม่ต้องยืนยันตัวตน
         * ผ่าน symlink public/storage — ย้ายมา disk นี้แล้วเมื่อ 2026-08-12 (SEC-001)
         *
         * ค่าที่เก็บใน DB ยังเป็น "/storage/<relative>" เหมือนเดิมทุกประการ
         * จึงไม่ต้อง backfill ฐานข้อมูล — ใช้ App\Support\StoredFile แปลงเป็น path จริง
         */
        'private_uploads' => [
            'driver' => 'local',
            'root' => storage_path('app/private/uploads'),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        'backup' => [
            'driver' => 'local',
            'root' => storage_path('backups'),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
