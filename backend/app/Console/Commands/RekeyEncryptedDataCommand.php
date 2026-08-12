<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * เข้ารหัสข้อมูลอ่อนไหวใหม่ด้วย APP_KEY ปัจจุบัน หลังจากหมุนกุญแจ
 *
 * วิธีใช้ (ลำดับสำคัญ):
 *   1. ตั้ง APP_PREVIOUS_KEYS=<กุญแจเดิม> และ APP_KEY=<กุญแจใหม่> ใน .env
 *   2. php artisan app:rekey-encrypted-data --dry-run   ตรวจว่าถอดรหัสได้ครบก่อน
 *   3. php artisan app:rekey-encrypted-data             เขียนจริง
 *   4. ลบ APP_PREVIOUS_KEYS ออกจาก .env
 *
 * Laravel จะถอดรหัสด้วย APP_KEY ก่อน ถ้าไม่สำเร็จจึงไล่ลอง APP_PREVIOUS_KEYS
 * คำสั่งนี้จึงอ่านค่าเดิมได้และเขียนกลับด้วยกุญแจใหม่เสมอ
 *
 * หมายเหตุสำคัญ: user_profiles.id_card_number_hash คำนวณจาก
 * sha256(เลขบัตร + config('app.key')) จึงต้องคำนวณใหม่ทุกครั้งที่หมุนกุญแจ
 * มิฉะนั้นการเข้าสู่ระบบด้วยเลขบัตรประชาชนจะใช้ไม่ได้ทั้งระบบ
 */
class RekeyEncryptedDataCommand extends Command
{
    protected $signature = 'app:rekey-encrypted-data {--dry-run : ตรวจอย่างเดียว ไม่เขียนฐานข้อมูล}';

    protected $description = 'เข้ารหัสข้อมูลอ่อนไหวใหม่ด้วย APP_KEY ปัจจุบัน และคำนวณ id_card_number_hash ใหม่';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('โหมด --dry-run : จะไม่มีการเขียนฐานข้อมูล');
        }

        if (empty(config('app.previous_keys'))) {
            $this->warn('ไม่พบ APP_PREVIOUS_KEYS — ถ้าเพิ่งหมุนกุญแจ การถอดรหัสจะล้มเหลว');
        }

        $failures = 0;

        $failures += $this->rekeyTable(
            table: 'user_profiles',
            encryptedColumns: ['id_card_number', 'passport_number'],
            dryRun: $dryRun,
            // เลขบัตรมี hash ที่ผูกกับ APP_KEY ต้องคำนวณใหม่พร้อมกัน
            extraUpdates: fn (array $plain) => filled($plain['id_card_number'] ?? null)
                ? ['id_card_number_hash' => hash('sha256', $plain['id_card_number'].config('app.key'))]
                : [],
        );

        $failures += $this->rekeyTable(
            table: 'users',
            encryptedColumns: ['phone'],
            dryRun: $dryRun,
        );

        $failures += $this->rekeySettings($dryRun);

        $this->newLine();

        if ($failures > 0) {
            $this->error("มี {$failures} ค่าที่ถอดรหัสไม่ได้ — ห้ามลบ APP_PREVIOUS_KEYS จนกว่าจะแก้ครบ");

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'ตรวจผ่านทั้งหมด ถอดรหัสได้ครบทุกค่า — รันซ้ำโดยไม่ใส่ --dry-run เพื่อเขียนจริง'
            : 'เข้ารหัสใหม่เรียบร้อย — ขั้นถัดไป: ลบ APP_PREVIOUS_KEYS ออกจาก .env แล้วเพิกถอน token ทั้งหมด');

        return self::SUCCESS;
    }

    /**
     * @param  array<int,string>  $encryptedColumns
     * @param  null|callable(array<string,?string>):array<string,mixed>  $extraUpdates
     */
    private function rekeyTable(string $table, array $encryptedColumns, bool $dryRun, ?callable $extraUpdates = null): int
    {
        $rows = DB::table($table)->get();
        $touched = 0;
        $failures = 0;

        foreach ($rows as $row) {
            $updates = [];
            $plain = [];

            foreach ($encryptedColumns as $column) {
                $current = $row->{$column} ?? null;

                if (blank($current)) {
                    continue;
                }

                try {
                    $value = Crypt::decryptString($current);
                } catch (DecryptException) {
                    // ไม่พิมพ์ค่าออกมา เพื่อไม่ให้ข้อมูลอ่อนไหวไปอยู่ใน log
                    $this->error("  ถอดรหัสไม่ได้: {$table}#{$row->id}.{$column}");
                    $failures++;

                    continue;
                }

                $plain[$column] = $value;
                $updates[$column] = Crypt::encryptString($value);
            }

            if ($extraUpdates !== null) {
                $updates += $extraUpdates($plain);
            }

            if ($updates === []) {
                continue;
            }

            if (! $dryRun) {
                DB::table($table)->where('id', $row->id)->update($updates);
            }

            $touched++;
        }

        $this->line(sprintf('  %-16s : %d/%d แถวที่มีข้อมูลเข้ารหัส', $table, $touched, $rows->count()));

        return $failures;
    }

    /** ค่า secret ในตาราง settings เก็บเป็น "enc:" + ciphertext */
    private function rekeySettings(bool $dryRun): int
    {
        $secretKeys = config('system_settings.secret_keys', []);

        if ($secretKeys === []) {
            $this->line('  settings         : ไม่มี secret_keys ที่ตั้งค่าไว้');

            return 0;
        }

        $rows = DB::table('settings')->whereIn('key', $secretKeys)->get();
        $touched = 0;
        $failures = 0;

        foreach ($rows as $row) {
            if (blank($row->value) || ! str_starts_with($row->value, 'enc:')) {
                continue;
            }

            try {
                $value = Crypt::decryptString(substr($row->value, 4));
            } catch (DecryptException) {
                $this->error("  ถอดรหัสไม่ได้: settings[{$row->key}]");
                $failures++;

                continue;
            }

            if (! $dryRun) {
                DB::table('settings')->where('id', $row->id)->update([
                    'value' => 'enc:'.Crypt::encryptString($value),
                ]);
            }

            $touched++;
        }

        $this->line(sprintf('  %-16s : %d/%d ค่า secret ที่เข้ารหัสอยู่', 'settings', $touched, $rows->count()));

        return $failures;
    }
}
