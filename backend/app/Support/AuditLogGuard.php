<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * trigger ระดับฐานข้อมูลที่ทำให้ประวัติการตรวจสอบเพิ่มได้อย่างเดียว (REDESIGN-2569 เฟส 5.5 · D-07)
 *
 * ใช้ทั้งจาก migration `2026_10_19_130000_make_audit_logs_append_only` และคำสั่ง `audit:protect`
 *
 * MySQL ที่เปิด binary log ไม่ยอมให้ผู้ใช้ที่ไม่มีสิทธิ์ SUPER สร้าง trigger (error 1419) และผู้ใช้ที่ไม่มีสิทธิ์
 * TRIGGER ก็สร้างไม่ได้ (error 1142) — กรณีนี้ install() ไม่โยน error เพื่อไม่ให้การ deploy ล้ม แต่คืนรายการ
 * ตารางที่ยังไม่ได้ป้องกัน ให้ผู้ดูแลฐานข้อมูลรัน `php artisan audit:protect` ด้วยบัญชีที่มีสิทธิ์ภายหลัง
 *
 * ข้อยกเว้นเดียวของ UPDATE: คอลัมน์ FK ที่ nullable เปลี่ยนเป็น NULL ได้ (ผลของ ON DELETE SET NULL)
 * ตารางที่ FK เป็น ON DELETE CASCADE กันเฉพาะ UPDATE เพราะการกัน DELETE จะทำให้ลบแถวต้นทางไม่ได้
 */
class AuditLogGuard
{
    public const MESSAGE = 'ห้ามแก้ไขหรือลบประวัติการตรวจสอบ';

    /** ตาราง => [คอลัมน์ FK ที่ยอมให้เป็น NULL ได้, กัน DELETE ด้วยหรือไม่] */
    public const TABLES = [
        'audit_logs' => [['actor_id', 'effective_actor_id'], true],
        'leave_audit_logs' => [['leave_id', 'user_id'], true],
        'saraban_audit_logs' => [['document_id', 'user_id'], true],
        'setting_audit_logs' => [['user_id'], true],
        'leave_action_logs' => [['user_id'], false],
        'personal_data_access_logs' => [[], false],
    ];

    /** error ของ MySQL ที่หมายถึงบัญชีปัจจุบันไม่มีสิทธิ์สร้าง trigger */
    private const PRIVILEGE_ERRORS = [1419, 1142, 1227];

    public static function supported(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb', 'sqlite'], true);
    }

    /**
     * สร้าง trigger ใหม่ทุกตาราง (รันซ้ำได้ — อ่านรายชื่อคอลัมน์ล่าสุดทุกครั้ง)
     *
     * @return array{protected: list<string>, failed: list<string>, reason: ?string}
     */
    public static function install(): array
    {
        $result = ['protected' => [], 'failed' => [], 'reason' => null];
        if (! self::supported()) {
            $result['reason'] = 'ฐานข้อมูลชนิดนี้ยังไม่รองรับ';

            return $result;
        }

        foreach (self::TABLES as $table => [$nullableKeys, $blockDelete]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                self::dropTriggers($table);
                $columns = Schema::getColumnListing($table);
                DB::getDriverName() === 'sqlite'
                    ? self::createSqlite($table, $columns, $nullableKeys, $blockDelete)
                    : self::createMysql($table, $columns, $nullableKeys, $blockDelete);
                $result['protected'][] = $table;
            } catch (QueryException $e) {
                if (! in_array((int) ($e->errorInfo[1] ?? 0), self::PRIVILEGE_ERRORS, true)) {
                    throw $e;
                }
                $result['failed'][] = $table;
                $result['reason'] = 'บัญชีฐานข้อมูลนี้ไม่มีสิทธิ์สร้าง trigger (ต้องมีสิทธิ์ TRIGGER และ SUPER หรือเปิด log_bin_trust_function_creators เมื่อเปิด binary log)';
            }
        }

        return $result;
    }

    public static function remove(): void
    {
        if (! self::supported()) {
            return;
        }

        foreach (array_keys(self::TABLES) as $table) {
            self::dropTriggers($table);
        }
    }

    /** ตารางที่ควรมี trigger แต่ยังไม่มี */
    public static function unprotectedTables(): array
    {
        if (! self::supported()) {
            return array_keys(self::TABLES);
        }

        $existing = DB::getDriverName() === 'sqlite'
            ? collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger'"))->pluck('name')
            : collect(DB::select('SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'))->pluck('name');

        return collect(self::TABLES)
            ->filter(fn ($config, $table) => Schema::hasTable($table))
            ->filter(fn ($config, $table) => ! $existing->contains("{$table}_append_only_update")
                || ($config[1] && ! $existing->contains("{$table}_append_only_delete")))
            ->keys()->values()->all();
    }

    private static function createMysql(string $table, array $columns, array $nullableKeys, bool $blockDelete): void
    {
        $unchanged = collect($columns)->map(function (string $c) use ($nullableKeys) {
            $same = "NEW.`{$c}` <=> OLD.`{$c}`";

            return in_array($c, $nullableKeys, true) ? "({$same} OR NEW.`{$c}` IS NULL)" : $same;
        })->implode(' AND ');
        $message = self::MESSAGE;

        DB::unprepared("CREATE TRIGGER `{$table}_append_only_update` BEFORE UPDATE ON `{$table}` FOR EACH ROW
            BEGIN
                IF NOT ({$unchanged}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
                END IF;
            END");

        if ($blockDelete) {
            DB::unprepared("CREATE TRIGGER `{$table}_append_only_delete` BEFORE DELETE ON `{$table}` FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
        }
    }

    private static function createSqlite(string $table, array $columns, array $nullableKeys, bool $blockDelete): void
    {
        $unchanged = collect($columns)->map(function (string $c) use ($nullableKeys) {
            $same = "NEW.\"{$c}\" IS OLD.\"{$c}\"";

            return in_array($c, $nullableKeys, true) ? "({$same} OR NEW.\"{$c}\" IS NULL)" : $same;
        })->implode(' AND ');
        $message = self::MESSAGE;

        DB::unprepared("CREATE TRIGGER \"{$table}_append_only_update\" BEFORE UPDATE ON \"{$table}\" FOR EACH ROW
            WHEN NOT ({$unchanged})
            BEGIN SELECT RAISE(ABORT, '{$message}'); END");

        if ($blockDelete) {
            DB::unprepared("CREATE TRIGGER \"{$table}_append_only_delete\" BEFORE DELETE ON \"{$table}\" FOR EACH ROW
                BEGIN SELECT RAISE(ABORT, '{$message}'); END");
        }
    }

    private static function dropTriggers(string $table): void
    {
        $quote = DB::getDriverName() === 'sqlite' ? '"' : '`';
        foreach (['update', 'delete'] as $kind) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$quote}{$table}_append_only_{$kind}{$quote}");
        }
    }
}
