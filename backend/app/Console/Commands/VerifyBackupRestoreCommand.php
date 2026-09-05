<?php

namespace App\Console\Commands;

use App\Services\Operations\SchedulerHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * INT-21 — ทดสอบกู้คืนจากไฟล์สำรองจริง (SoT §27)
 *
 * **ไฟล์สำรองที่ไม่เคยกู้คืนได้สำเร็จ ไม่ใช่ไฟล์สำรอง — เป็นไฟล์เฉย ๆ**
 *
 * นี่คือความผิดพลาดที่พบบ่อยที่สุดในระบบราชการ: สำรองข้อมูลทุกคืนมาห้าปี
 * แล้ววันที่ต้องใช้จริงจึงพบว่าไฟล์เสีย หรือ dump ไม่ครบ หรือกู้แล้วข้อมูลหาย
 *
 * คำสั่งนี้ทำสิ่งที่คนมักไม่ทำ: **กู้คืนจริงลงฐานข้อมูลชั่วคราว** แล้วตรวจว่า
 * ตารางสำคัญมีข้อมูลครบ จากนั้นลบฐานชั่วคราวทิ้ง
 *
 * **ไม่แตะฐานข้อมูลที่ใช้งานอยู่เลย** — สร้างฐานชื่อใหม่ กู้ลงไป ตรวจ แล้วลบ
 * ถ้าขั้นใดล้มเหลว ฐานชั่วคราวยังถูกลบเสมอ (finally)
 */
class VerifyBackupRestoreCommand extends Command
{
    protected $signature = 'backup:verify-restore
                            {--keep : เก็บฐานข้อมูลที่กู้คืนไว้เพื่อตรวจสอบด้วยมือ}';

    protected $description = 'กู้คืนไฟล์สำรองล่าสุดลงฐานชั่วคราวเพื่อพิสูจน์ว่ากู้ได้จริง';

    /** ชื่อ connection ชั่วคราวสำหรับการซ้อมกู้คืน */
    private const CONNECTION = 'restore_check';

    /** ตารางที่ต้องมีข้อมูลหลังกู้คืน — ว่างเปล่าแปลว่า dump ไม่ครบ */
    private const REQUIRED_TABLES = ['users', 'permissions', 'menus', 'migrations'];

    public function handle(SchedulerHeartbeat $heartbeat): int
    {
        if (app()->environment('production')) {
            $this->error('คำสั่งนี้สร้างและลบฐานข้อมูล จึงรันบน production ไม่ได้');

            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->warn('ข้าม — รองรับเฉพาะ MySQL (ฐานที่ใช้งานจริง)');

            return self::SUCCESS;
        }

        /*
         * ใช้บัญชีฐานข้อมูลแยกสำหรับการซ้อมกู้คืน (SoT §26 — least privilege)
         *
         * **บัญชีของแอปต้องสร้างฐานข้อมูลไม่ได้** และนั่นถูกต้องแล้ว — การซ้อม
         * กู้คืนต้องใช้บัญชีที่มีสิทธิ์สูงกว่า ซึ่งควรมีอยู่เฉพาะที่ที่ซ้อมจริง
         * ไม่ใช่ผูกไว้กับแอปตลอดเวลา
         *
         * ถ้ายังไม่ได้ตั้ง ให้บอกตรง ๆ ว่าทำไม่ได้ **แทนที่จะลดสิทธิ์ของแอป
         * ลงมาให้ผ่าน** — และทะเบียนหลักฐานจะยังคงเป็น NO_EVIDENCE ซึ่งถูกต้อง
         */
        if (! $this->configureRestoreConnection()) {
            $this->error(
                'ยังไม่ได้ตั้งบัญชีฐานข้อมูลสำหรับซ้อมกู้คืน — ตั้ง BACKUP_RESTORE_TEST_USERNAME '
                .'และ BACKUP_RESTORE_TEST_PASSWORD ของบัญชีที่มีสิทธิ์ CREATE/DROP DATABASE'
            );

            return self::FAILURE;
        }

        $archive = $this->latestBackup();

        if ($archive === null) {
            $this->error('ไม่พบไฟล์สำรอง — รัน `php artisan backup:run` ก่อน');

            return self::FAILURE;
        }

        $this->info("ไฟล์สำรองล่าสุด: {$archive}");

        $startedAt = microtime(true);
        $target = 'restore_check_'.date('YmdHis');
        $dump = null;

        try {
            $dump = $this->extractDump($archive);

            if ($dump === null) {
                $this->error('ในไฟล์สำรองไม่มี dump ของฐานข้อมูล');

                return self::FAILURE;
            }

            $this->createDatabase($target);
            $this->importDump($target, $dump);

            $counts = $this->inspect($target);
            $seconds = round(microtime(true) - $startedAt, 1);

            $this->table(['ตาราง', 'จำนวนแถว'], collect($counts)->map(
                fn (int $count, string $table) => [$table, $count],
            )->values()->all());

            $empty = array_keys(array_filter($counts, fn (int $count) => $count === 0));

            if ($empty !== []) {
                $this->error('กู้คืนแล้วแต่ตารางสำคัญว่างเปล่า: '.implode(', ', $empty));

                return self::FAILURE;
            }

            /*
             * RTO ที่วัดได้จริง (SoT §27) — เวลาที่ใช้กู้คืนฐานข้อมูลจากไฟล์สำรอง
             * ไม่ใช่ RTO ทั้งระบบ (ยังต้องรวมเวลาตั้งเครื่องและตั้งค่า) แต่เป็น
             * ส่วนที่วัดซ้ำได้ · ตัวเลขที่วัดจริงมีค่ากว่าตัวเลขที่ประมาณไว้ในเอกสาร
             */
            $heartbeat->record('backup:verify-restore', 10080, [
                'archive' => $archive,
                'restore_seconds' => $seconds,
                'tables' => $counts,
            ]);

            $this->info("ผ่าน — กู้คืนสำเร็จใน {$seconds} วินาที");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('กู้คืนไม่สำเร็จ: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($dump !== null) {
                @unlink($dump);
            }

            if (! $this->option('keep')) {
                $this->dropDatabase($target);
            } else {
                $this->warn("เก็บฐานข้อมูล {$target} ไว้ตามที่สั่ง — อย่าลืมลบเอง");
            }
        }
    }

    /**
     * ตั้งค่า connection `restore_check` จากบัญชีที่มีสิทธิ์สร้างฐานข้อมูล
     *
     * คืน `false` เมื่อยังไม่ได้ตั้งค่า — ผู้เรียกต้องหยุดและบอกผู้ใช้
     */
    private function configureRestoreConnection(): bool
    {
        $username = config('backup.restore_test.username');
        $password = config('backup.restore_test.password');

        if (blank($username)) {
            return false;
        }

        $base = config('database.connections.'.config('database.default'));

        config([
            'database.connections.'.self::CONNECTION => [
                ...$base,
                'username' => $username,
                'password' => $password,
                // ไม่ผูกกับฐานใดฐานหนึ่ง เพราะต้องสร้างฐานใหม่ก่อนจึงจะมีที่ให้ต่อ
                'database' => null,
            ],
        ]);

        DB::purge(self::CONNECTION);

        return true;
    }

    /**
     * ตัวเลือก TLS ของ client — ชื่อ flag ต่างกันระหว่าง MySQL กับ MariaDB
     *
     * เซิร์ฟเวอร์ในคอนเทนเนอร์ใช้ใบรับรองที่ออกเอง client จึงปฏิเสธการเชื่อมต่อ
     * ด้วย "self-signed certificate in certificate chain"
     *
     * **ยังเข้ารหัสอยู่ เพียงแต่ไม่ตรวจสายความน่าเชื่อถือของใบรับรอง** —
     * เหมาะกับการเชื่อมต่อภายในเครือข่ายคอนเทนเนอร์ และไม่ปิดการเข้ารหัสทิ้ง
     *
     * `default-mysql-client` ของ Debian คือ MariaDB ซึ่งไม่รู้จัก `--ssl-mode`
     * จึงต้องตรวจก่อนว่าเป็น client ตัวไหน แทนการเดา
     *
     * @return array<int,string>
     */
    private function tlsOptions(): array
    {
        $override = env('BACKUP_RESTORE_TEST_SSL_OPTION');

        if (filled($override)) {
            return [$override];
        }

        exec(escapeshellarg('mysql').' --version 2>&1', $output);
        $isMariaDb = str_contains(strtolower(implode(' ', $output)), 'mariadb');

        return $isMariaDb
            ? ['--ssl-verify-server-cert=0']
            : ['--ssl-mode=PREFERRED'];
    }

    /** connection ที่ใช้สร้าง/ลบฐานชั่วคราว — แยกจาก connection ของแอป */
    private function connection()
    {
        return DB::connection(self::CONNECTION);
    }

    private function latestBackup(): ?string
    {
        $disk = Storage::disk(config('backup.backup.destination.disks')[0] ?? 'backup');

        $archives = collect($disk->allFiles())
            ->filter(fn (string $path) => str_ends_with($path, '.zip'))
            ->sortBy(fn (string $path) => $disk->lastModified($path));

        return $archives->last() !== null ? $disk->path($archives->last()) : null;
    }

    /** แตก dump ของฐานข้อมูลออกจาก zip มาไว้ในไฟล์ชั่วคราว */
    private function extractDump(string $archive): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($archive) !== true) {
            throw new \RuntimeException('เปิดไฟล์สำรองไม่ได้');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                if (! str_ends_with(strtolower((string) $name), '.sql')) {
                    continue;
                }

                $target = tempnam(sys_get_temp_dir(), 'restore-dump-').'.sql';
                file_put_contents($target, $zip->getFromIndex($i));

                return $target;
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    private function createDatabase(string $name): void
    {
        $this->dropDatabase($name);

        $this->connection()->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private function dropDatabase(string $name): void
    {
        try {
            $this->connection()->statement("DROP DATABASE IF EXISTS `{$name}`");
        } catch (Throwable $exception) {
            $this->warn("ลบฐานข้อมูล {$name} ไม่สำเร็จ: ".$exception->getMessage());
        }
    }

    /**
     * นำ dump เข้าฐานชั่วคราวด้วย `mysql` client
     *
     * ใช้ client แทนการอ่าน SQL มารันทีละคำสั่งใน PHP เพราะ dump มีคำสั่งที่
     * ต้องตีความแบบเดียวกับตอนสร้าง (เช่น `/*!40101` conditional comments)
     * การรันเองจะพลาดในจุดที่ยากจะสังเกต
     */
    private function importDump(string $database, string $dump): void
    {
        $config = config('database.connections.'.self::CONNECTION);

        $command = implode(' ', array_map('escapeshellarg', [
            'mysql',
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            '--password='.$config['password'],
            ...$this->tlsOptions(),
            $database,
        ])).' < '.escapeshellarg($dump).' 2>&1';

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException('นำ dump เข้าฐานไม่สำเร็จ: '.implode("\n", array_slice($output, 0, 5)));
        }
    }

    /** @return array<string,int> */
    private function inspect(string $database): array
    {
        $counts = [];

        foreach (self::REQUIRED_TABLES as $table) {
            $rows = $this->connection()->select("SELECT COUNT(*) as total FROM `{$database}`.`{$table}`");
            $counts[$table] = (int) ($rows[0]->total ?? 0);
        }

        return $counts;
    }
}
