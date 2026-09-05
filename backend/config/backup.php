<?php

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

return [

    /*
     * INT-22 — บัญชีฐานข้อมูลสำหรับซ้อมกู้คืน (SoT §26, §27)
     *
     * **แยกจากบัญชีของแอปโดยตั้งใจ** — บัญชีของแอปต้องสร้างฐานข้อมูลไม่ได้
     * ตามหลัก least privilege · การซ้อมกู้คืนต้องใช้สิทธิ์ที่สูงกว่า ซึ่งควรมี
     * เฉพาะบนเครื่องที่ซ้อมจริง ไม่ใช่ผูกไว้กับแอปตลอดเวลา
     *
     * ไม่ตั้ง = `backup:verify-restore` ปฏิเสธที่จะทำงานและบอกเหตุผล
     * และทะเบียนหลักฐานยังคงเป็น NO_EVIDENCE ซึ่งถูกต้อง
     */
    'restore_test' => [
        'username' => env('BACKUP_RESTORE_TEST_USERNAME'),
        'password' => env('BACKUP_RESTORE_TEST_PASSWORD'),
    ],

    'backup' => [
        /*
         * The name of this application. You can use this name to monitor
         * the backups.
         */
        'name' => env('APP_NAME', 'laravel-backup'),

        'source' => [
            'files' => [
                /*
                 * The list of directories and files that will be included in the backup.
                 */
                'include' => [
                    storage_path('app'),
                ],

                /*
                 * These directories and files will be excluded from the backup.
                 *
                 * Directories used by the backup process will automatically be excluded.
                 */
                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    storage_path('framework'),
                ],

                /*
                 * Determines if symlinks should be followed.
                 */
                'follow_links' => false,

                /*
                 * Determines if it should avoid unreadable folders.
                 */
                'ignore_unreadable_directories' => false,

                /*
                 * This path is used to make directories in resulting zip-file relative
                 * Set to `null` to include complete absolute path
                 * Example: base_path()
                 */
                'relative_path' => storage_path('app'),
            ],

            /*
             * The names of the connections to the databases that should be backed up
             * MySQL, PostgreSQL, SQLite and Mongo databases are supported.
             *
             * The content of the database dump may be customized for each connection
             * by adding a 'dump' key to the connection settings in config/database.php.
             * E.g.
             * 'mysql' => [
             *       ...
             *      'dump' => [
             *           'exclude_tables' => [
             *                'table_to_exclude_from_backup',
             *                'another_table_to_exclude'
             *            ]
             *       ],
             * ],
             *
             * If you are using only InnoDB tables on a MySQL server, you can
             * also supply the useSingleTransaction option to avoid table locking.
             *
             * E.g.
             * 'mysql' => [
             *       ...
             *      'dump' => [
             *           'useSingleTransaction' => true,
             *       ],
             * ],
             *
             * For a complete list of available customization options, see https://github.com/spatie/db-dumper
             */
            'databases' => [
                env('DB_CONNECTION', 'mysql'),
            ],
        ],

        /*
         * The database dump can be compressed to decrease disk space usage.
         *
         * Out of the box Laravel-backup supplies
         * Spatie\DbDumper\Compressors\GzipCompressor::class.
         *
         * You can also create custom compressor. More info on that here:
         * https://github.com/spatie/db-dumper#using-compression
         *
         * If you do not want any compressor at all, set it to null.
         */
        'database_dump_compressor' => null,

        /*
         * If specified, the database dumped file name will contain a timestamp (e.g.: 'Y-m-d-H-i-s').
         */
        'database_dump_file_timestamp_format' => null,

        /*
         * The base of the dump filename, either 'database' or 'connection'
         *
         * If 'database' (default), the dumped filename will contain the database name.
         * If 'connection', the dumped filename will contain the connection name.
         */
        'database_dump_filename_base' => 'database',

        /*
         * The file extension used for the database dump files.
         *
         * If not specified, the file extension will be .archive for MongoDB and .sql for all other databases
         * The file extension should be specified without a leading .
         */
        'database_dump_file_extension' => '',

        'destination' => [
            /*
             * The compression algorithm to be used for creating the zip archive.
             *
             * If backing up only database, you may choose gzip compression for db dump and no compression at zip.
             *
             * Some common algorithms are listed below:
             * ZipArchive::CM_STORE (no compression at all; set 0 as compression level)
             * ZipArchive::CM_DEFAULT
             * ZipArchive::CM_DEFLATE
             * ZipArchive::CM_BZIP2
             * ZipArchive::CM_XZ
             *
             * For more check https://www.php.net/manual/zip.constants.php and confirm it's supported by your system.
             */
            'compression_method' => ZipArchive::CM_DEFAULT,

            /*
             * The compression level corresponding to the used algorithm; an integer between 0 and 9.
             *
             * Check supported levels for the chosen algorithm, usually 1 means the fastest and weakest compression,
             * while 9 the slowest and strongest one.
             *
             * Setting of 0 for some algorithms may switch to the strongest compression.
             */
            'compression_level' => 9,

            /*
             * The filename prefix used for the backup zip file.
             */
            'filename_prefix' => '',

            /*
             * The disk names on which the backups will be stored.
             */
            'disks' => [
                'backup',
            ],

            /*
             * Determines whether to allow backups to continue when some targets fail instead of failing completely.
             */
            'continue_on_failure' => false,
        ],

        /*
         * ไดเรกทอรีชั่วคราวระหว่างสร้างไฟล์สำรอง
         *
         * **ต้องไม่อยู่บน bind mount** (RISK-56 · แก้ใน INT-22)
         *
         * ค่าเดิมคือ `storage/app/backup-temp` ซึ่งบนเครื่องที่ bind mount `storage`
         * มาจากโฮสต์ (Docker Desktop ใช้ **virtiofs**) จะทำให้การสำรองข้อมูล
         * **ล้มเหลวทุกครั้ง** ด้วย `mkdir(): File exists`
         *
         * สาเหตุ: `spatie/temporary-directory` ทำ `rmdir()` แล้ว `mkdir()` ทันที
         * ในบรรทัดถัดไป · virtiofs แคช metadata ของไดเรกทอรีไว้ ทำให้ไดเรกทอรี
         * ที่เพิ่งลบยังปรากฏอยู่ชั่วขณะ `mkdir()` จึงชนกับของเดิม
         *
         * ค่าเริ่มต้นจึงย้ายมาอยู่บนดิสก์ของ container เอง ซึ่งถูกต้องทั้งบน
         * เครื่องพัฒนาและ production · ไฟล์ที่พักไว้ที่นี่มีขนาดเท่าไฟล์สำรอง
         * หนึ่งชุดเท่านั้น และถูกลบทิ้งเมื่อเสร็จ
         *
         * ตั้ง `BACKUP_TEMPORARY_DIRECTORY` ทับได้ถ้าพื้นที่ `/tmp` ไม่พอ
         */
        'temporary_directory' => env(
            'BACKUP_TEMPORARY_DIRECTORY',
            rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'eleave-backup-temp',
        ),

        /*
         * The password to be used for archive encryption.
         * Set to `null` to disable encryption.
         */
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),

        /*
         * The encryption algorithm to be used for archive encryption.
         * Set to 'none' to disable encryption.
         *
         * Supported: 'none', 'default', 'aes128', 'aes192', 'aes256'
         *
         * When set to 'default', we'll use AES-256 if available on your system.
         */
        'encryption' => 'default',

        /*
         * After creating the zip, verify it can be opened and contains files.
         * Recommended for critical backups but adds a small overhead.
         */
        'verify_backup' => true,

        /*
         * The number of attempts, in case the backup command encounters an exception
         */
        'tries' => 1,

        /*
         * The number of seconds to wait before attempting a new backup if the previous try failed
         * Set to `0` for none
         */
        'retry_delay' => 0,
    ],

    /*
     * You can get notified when specific events occur. Out of the box you can use 'mail' and 'slack'.
     * For Slack you need to install laravel/slack-notification-channel.
     *
     * You can also use your own notification classes, just make sure the class is named after one of
     * the `Spatie\Backup\Notifications\Notifications` classes.
     */
    'notifications' => [
        /*
         * INT-22 (RISK-57) — ส่งอีเมลเฉพาะเมื่อตั้งที่อยู่ไว้จริง
         *
         * ถ้าไม่ตั้ง `BACKUP_NOTIFICATION_EMAIL` ช่องทางจะว่าง แทนที่จะพยายามส่ง
         * ไปยัง `null` แล้วล้มเหลวซ้อนกับความล้มเหลวเดิม
         *
         * **ความล้มเหลวของการสำรองยังคงมองเห็นได้เสมอ** ผ่านคิวข้อผิดพลาด
         * (`RecordBackupOutcome` · INT-21) ซึ่งไม่ขึ้นกับการตั้งค่านี้เลย
         *
         * ไม่ส่งอีเมลตอนสำเร็จ — การแจ้งเตือนที่มาทุกวันจนคนเลิกอ่าน ทำให้
         * ฉบับที่แจ้งว่าล้มเหลวถูกกวาดผ่านไปด้วย
         */
        'notifications' => filled(env('BACKUP_NOTIFICATION_EMAIL')) ? [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ] : [
            BackupHasFailedNotification::class => [],
            UnhealthyBackupWasFoundNotification::class => [],
            CleanupHasFailedNotification::class => [],
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ],

        /*
         * Here you can specify the notifiable to which the notifications should be sent. The default
         * notifiable will use the variables specified in this config file.
         */
        'notifiable' => Notifiable::class,

        'mail' => [
            /*
             * INT-22 (RISK-57) — เดิมเป็น `your@example.com` ซึ่งเป็นค่าตัวอย่าง
             * ของแพ็กเกจ · การสำรองข้อมูลที่ล้มเหลวทุกคืนจึงส่งอีเมลไปที่ที่ไม่มีใครอ่าน
             *
             * **ค่าเริ่มต้นเป็น `null` โดยตั้งใจ ไม่ใช่ที่อยู่สมมุติ** — ที่อยู่สมมุติ
             * ทำให้ระบบ "ดูเหมือนแจ้งเตือนแล้ว" ทั้งที่ไม่มีใครได้รับ ซึ่งอันตราย
             * กว่าการไม่ตั้งค่าเลย · ไม่ตั้ง = ไม่ส่งอีเมล
             *
             * **ความล้มเหลวไม่เงียบแม้ไม่ตั้งค่านี้** เพราะ `RecordBackupOutcome`
             * (INT-21) ยกเข้าคิวข้อผิดพลาดระดับ critical เสมอ · อีเมลเป็นช่องทาง
             * เสริม ไม่ใช่ช่องทางเดียว
             */
            /*
             * แพ็กเกจตรวจชนิดของค่านี้เสมอแม้ไม่ได้เปิดช่องทางอีเมล จึงเป็น `null`
             * ไม่ได้ · ค่าสำรองใช้โดเมน `.invalid` ซึ่ง RFC 2606 สงวนไว้ให้
             * **ส่งไม่ถึงโดยเจตนา** — ต่างจาก `example.com` ที่ดูเหมือนที่อยู่จริง
             *
             * ค่านี้ไม่ถูกใช้เลยเมื่อไม่ได้ตั้ง `BACKUP_NOTIFICATION_EMAIL`
             * เพราะช่องทางทั้งหมดว่างอยู่แล้ว
             */
            'to' => env('BACKUP_NOTIFICATION_EMAIL', 'backup-notifications@unconfigured.invalid'),

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Example'),
            ],
        ],

        'slack' => [
            'webhook_url' => '',

            /*
             * If this is set to null the default channel of the webhook will be used.
             */
            'channel' => null,

            'username' => null,

            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',

            /*
             * If this is an empty string, the name field on the webhook will be used.
             */
            'username' => '',

            /*
             * If this is an empty string, the avatar on the webhook will be used.
             */
            'avatar_url' => '',
        ],

        /*
         * A generic webhook channel that POSTs JSON to a URL.
         * Useful for Mattermost, Microsoft Teams, or custom integrations.
         */
        'webhook' => [
            'url' => '',
        ],
    ],

    /*
     * The log channel used for backup activity messages.
     *
     * Set to a channel name defined in config/logging.php to use that channel.
     * Set to false to disable backup logging entirely.
     * Set to null to use the default log channel.
     */
    'log_channel' => null,

    /*
     * Here you can specify which backups should be monitored.
     * If a backup does not meet the specified requirements the
     * UnHealthyBackupWasFound event will be fired.
     */
    'monitor_backups' => [
        [
            'name' => env('APP_NAME', 'laravel-backup'),
            'disks' => ['backup'],
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => 5000,
            ],
        ],

        /*
        [
            'name' => 'name of the second app',
            'disks' => ['local', 's3'],
            'health_checks' => [
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays::class => 1,
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes::class => 5000,
            ],
        ],
        */
    ],

    'cleanup' => [
        /*
         * The strategy that will be used to cleanup old backups. The default strategy
         * will keep all backups for a certain amount of days. After that period only
         * a daily backup will be kept. After that period only weekly backups will
         * be kept and so on.
         *
         * No matter how you configure it the default strategy will never
         * delete the newest backup.
         */
        'strategy' => DefaultStrategy::class,

        'default_strategy' => [
            /*
             * The number of days for which backups must be kept.
             */
            'keep_all_backups_for_days' => 7,

            /*
             * After the "keep_all_backups_for_days" period is over, the most recent backup
             * of that day will be kept. Older backups within the same day will be removed.
             * If you create backups only once a day, no backups will be removed yet.
             */
            'keep_daily_backups_for_days' => 16,

            /*
             * After the "keep_daily_backups_for_days" period is over, the most recent backup
             * of that week will be kept. Older backups within the same week will be removed.
             * If you create backups only once a week, no backups will be removed yet.
             */
            'keep_weekly_backups_for_weeks' => 8,

            /*
             * After the "keep_weekly_backups_for_weeks" period is over, the most recent backup
             * of that month will be kept. Older backups within the same month will be removed.
             */
            'keep_monthly_backups_for_months' => 4,

            /*
             * After the "keep_monthly_backups_for_months" period is over, the most recent backup
             * of that year will be kept. Older backups within the same year will be removed.
             */
            'keep_yearly_backups_for_years' => 2,

            /*
             * After cleaning up the backups remove the oldest backup until
             * this amount of megabytes has been reached.
             * Set null for unlimited size.
             */
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],

        /*
         * The number of attempts, in case the cleanup command encounters an exception
         */
        'tries' => 1,

        /*
         * The number of seconds to wait before attempting a new cleanup if the previous try failed
         * Set to `0` for none
         */
        'retry_delay' => 0,
    ],

];
